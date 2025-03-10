<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\PlaceStopMarketOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class CalculateWAPAndAdjustProfitOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    public float $originalPrice;

    public float $originalQuantity;

    public function __construct(int $orderId, float $originalPrice, float $originalQuantity)
    {
        $this->order = Order::with(['position.account.apiSystem'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);

        $this->originalPrice = $originalPrice;
        $this->originalQuantity = $originalQuantity;
    }

    public function computeApiable()
    {
        $wap = $this->position->calculateWAP();

        if (array_key_exists('resync', $wap) && $wap['resync'] == true) {
            // Something happened and we need to resync the orders. Then we can try again the core job.
            User::admin()->get()->each(function ($user) use ($wap) {
                $user->pushover(
                    message: "WAP calculation for ({$this->position->parsedTradingPair} Position ID: {$this->position->id}) orders need to be resynced and will be retried. Error: {$wap['error']}",
                    title: 'WAP calculation orders need to be resynced and retried later',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            $this->position->loadMissing('orders');
            foreach ($this->position->orders as $order) {
                $order->apiSync();
            }

            $this->coreJobQueue->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());

            return;
        }

        if ($wap['quantity'] != null && $wap['price'] != null) {
            // Obtain info from the profit order.
            $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

            // Modify order for the new WAP values.
            $apiResponse = $profitOrder->apiModify($wap['quantity'], $wap['price']);

            // Inform the order observer not to put the PROFIT order back on its original values.
            $this->position->update(['wap_triggered' => true]);

            // Update all orders to magnet_status = activated, to 'cancelled'.
            $this->position->orders()->where('magnet_status', 'activated')->update(['magnet_status' => 'cancelled']);

            // How many orders do we have filled?
            $totalFilledOrders = $this->position
                ->orders
                ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
                ->where('status', 'FILLED')->count();

            if ($totalFilledOrders >= $this->account->filled_orders_to_notify) {
                User::admin()->get()->each(function ($user) use ($wap, $totalFilledOrders) {
                    $user->pushover(
                        message: "WAP [{$totalFilledOrders}] - {$this->position->parsedTradingPair} ({$this->position->direction}), Qty: {$this->originalQuantity} to {$wap['quantity']}, Price: {$this->originalPrice} to {$wap['price']} USDT",
                        title: 'WAP triggered',
                        applicationKey: 'nidavellir_orders'
                    );
                });
            }

            /**
             * Now verify if all limit orders are filled. If so, we need to
             * activate a stop loss after a specific duration, to avoid extra
             * loses, in case the market continues to crash. Better to less on
             * loss than to be fully liquidated.
             */
            if ($this->position->hasAllLimitOrdersFilled()) {
                $dispatchAt = now()->addMinutes((int) $this->account->stop_order_trigger_duration_minutes);

                CoreJobQueue::create([
                    'class' => PlaceStopMarketOrderJob::class,
                    'queue' => 'orders',
                    'arguments' => [
                        'positionId' => $this->position->id,
                    ],
                    'dispatch_after' => $dispatchAt,
                ]);

                User::admin()->get()->each(function ($user) use ($dispatchAt) {
                    $user->pushover(
                        message: "Stop Market order triggered for {$this->position->parsedTradingPair} to be placed at {$dispatchAt->format('H:i:s')}",
                        title: "Stop Market Order Scheduled - {$this->position->parsedTradingPair}",
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }
        } else {
            throw new \Exception('A WAP calculation was requested but there was an error. Position ID: '.$this->position->id);
        }
    }
}
