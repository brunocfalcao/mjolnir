<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
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

        if ($wap['quantity'] != null && $wap['price'] != null) {
            // Obtain info from the profit order.
            $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

            // Modify order for the new WAP values.
            $apiResponse = $profitOrder->apiModify($wap['quantity'], $wap['price']);

            // Inform the order observer not to put the PROFIT order back on its original values.
            $this->position->update(['wap_triggered' => true]);

            // How many orders do we have filled?
            $totalFilledOrders = $this->position
                ->orders
                ->where('type', 'LIMIT')
                ->where('status', 'FILLED')->count();

            User::admin()->get()->each(function ($user) use ($wap, $totalFilledOrders) {
                $user->pushover(
                    message: "WAP [{$totalFilledOrders}] - {$this->position->parsedTradingPair} ({$this->position->direction}), Qty: {$this->originalQuantity} to {$wap['quantity']}, Price: {$this->originalPrice} to {$wap['price']} USDT",
                    title: 'WAP triggered',
                    applicationKey: 'nidavellir_orders'
                );
            });

            /**
             * Now verify if all limit orders are filled. If so, we need to
             * activate a stop loss after a specific duration, to avoid extra
             * loses, in case the market continues to crash. Better to less on
             * loss than to be fully liquidated.
             */
            if ($position->allLimitOrdersFilled()) {
            }
        } else {
            throw new \Exception('A WAP calculation was requested but there was an error. Position ID: '.$this->position->id);
        }
    }
}
