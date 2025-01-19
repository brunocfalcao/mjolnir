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

    public function __construct(int $orderId)
    {
        $this->order = Order::with(['position.account.apiSystem'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        $wap = $this->position->calculateWAP();

        if ($wap['quantity'] != null && $wap['price'] != null) {
            // Obtain info from the profit order.
            $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');
            $originalPrice = $profitOrder->price;
            $originalQuantity = $profitOrder->quantity;

            $apiResponse = $profitOrder->apiModify($wap['quantity'], $wap['price']);

            User::admin()->get()->each(function ($user) use ($originalPrice, $originalQuantity, $profitOrder) {
                $user->pushover(
                    message: "{$this->position->parsedTradingPair} WAP triggered. Price: {$originalPrice} to {$profitOrder->price}, Qty: {$originalQuantity} to {$profitOrder->quantity}",
                    title: 'WAP triggered',
                    applicationKey: 'nidavellir_orders'
                );
            });

            // Inform the order observer not to put the PROFIT order back on its original values.
            $this->position->update(['wap_triggered' => true]);
        } else {
            throw new \Exception('A WAP calculation was requested but there was an error. Position ID: '.$this->position->id);
        }
    }
}
