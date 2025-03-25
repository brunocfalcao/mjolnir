<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\UpdateWAP;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\PlaceStopMarketOrderJob;
use Nidavellir\Mjolnir\Jobs\Processes\StorePositionIndicators\StorePositionIndicatorsLifecycleJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Indicator;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class ResettleProfitOrderFromWAPJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public float $newPrice;

    public Order $olderProfitOrder;

    public function __construct(int $positionId, int $olderProfitOrderId, float $newPrice)
    {
        // info('[ResettleProfitOrderJob] - Starting resettlement Profit Order');

        $this->position = Position::findOrFail($positionId);

        $this->position->load('exchangeSymbol');
        $this->position->load('orders');

        $this->olderProfitOrder = Order::findOrFail($olderProfitOrderId);
        $this->newPrice = api_format_price($newPrice, $this->position->exchangeSymbol);

        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        // Lets create a new profit order, since the previous one was cancelled.
        $newProfitOrder = Order::create([
            'position_id' => $this->olderProfitOrder->position_id,
            'type' => 'PROFIT',
            'status' => 'NEW',
            'side' => $this->olderProfitOrder->side,
            'quantity' => 0,
            'price' => $this->newPrice,
        ]);

        // info("[ResettleProfitOrderJob] - New Profit Order created ID {$newProfitOrder->id} with price {$newProfitOrder->price}");

        // Now place the new profit order.
        // info('[ResettleProfitOrderJob] - Placing new Profit Order ...');
        $newProfitOrder->apiPlace();

        // info("[ResettleProfitOrderJob] - Profit Order exchange ID: {$newProfitOrder->exchange_order_id}");

        // Update all orders to magnet_status = activated, to 'cancelled'.
        $this->position
            ->orders()
            ->where('magnet_status', 'activated')
            ->update(['magnet_status' => 'cancelled']);

        // How many orders do we have filled?
        $totalFilledOrders = $this->position
            ->orders
            ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
            ->where('status', 'FILLED')->count();

        // Notify admins in case X limit orders were filled.
        if ($totalFilledOrders >= $this->account->filled_orders_to_notify) {
            User::admin()->get()->each(function ($user) use ($totalFilledOrders) {
                $user->pushover(
                    message: "WAP [{$totalFilledOrders}] - {$this->position->parsedTradingPair} ({$this->position->direction}), Price: {$this->olderProfitOrder->price} to {$this->newPrice} USDT",
                    title: 'WAP triggered',
                    applicationKey: 'nidavellir_orders'
                );
            });
        }

        // Last observers skip statuses, and we are off to go.
        $newProfitOrder->updateQuietly(['skip_observer' => true]);
        $this->position->update(['wap_triggered' => false]);

        /**
         * Now verify if all limit orders are filled. If so, we need to
         * activate a stop loss after a specific duration, to avoid extra
         * loses, in case the market continues to crash. Better to less on
         * loss than to be fully liquidated.
         */
        if ($this->position->hasAllLimitOrdersFilled()) {
            $dispatchAt = now()->addMinutes((int) $this->account->stop_order_trigger_duration_minutes);

            // Prepare to place the stop loss market order.
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

            // Start grabbing indicators data.
            Indicator::active()->apiable()->where('type', 'stop-loss')->chunk(3, function ($indicators) {

                $indicatorIds = implode(',', $indicators->pluck('id')->toArray());

                CoreJobQueue::create([
                    'class' => StorePositionIndicatorsLifecycleJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'positionId' => $this->position->id,
                        'indicatorIds' => $indicatorIds,
                        'timeframe' => '1h',
                    ],
                ]);
            });
        }
    }
}
