<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class PlaceOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    // Specific configuration to allow more retry flexibility.
    public int $workerServerBackoffSeconds = 3;

    public int $retries = 10;
    // -----

    public function __construct(int $orderId)
    {
        $this->order = Order::with(['position.account.apiSystem'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function authorize()
    {
        // In case the order is not market, we need to verify if the market is created first.
        return $this->order->type == 'MARKET' || $this->marketOrderSynced();
    }

    public function computeApiable()
    {
        info('[PlaceOrderJob] - Order ID: '.$this->order->id.', placing order on API...');

        $this->order->changeToSyncing();

        $result = $this->order->apiPlace();

        $this->order->update([
            'exchange_order_id' => $result['orderId'],
        ]);

        // Sync order.
        $this->order->apiSync();

        $this->order->changeToSynced();

        info('[PlaceOrderJob] - Order ID: '.$this->order->id.', order placed and synced with exchange id '.$this->order->exchange_order_id);

        return $result;
    }

    public function marketOrderSynced()
    {
        return $this->order->position->orders()
            ->where('is_syncing', false)
            ->where('type', 'MARKET')
            ->where('status', 'FILLED')
            ->exists();
    }

    public function resolveException(\Throwable $e)
    {
        // Cancels all open orders (except the market order itself).
        // $this->order->position->apiCancelAllOrders();

        // Opens an opposite market order with same quantity to close position.
        // $this->order->position->apiCancelMarketOrder();

        // Stop the order.
        $this->order->update([
            'status' => 'FAILED',
            'is_syncing' => false,
            'error_message' => $e->getMessage(),
        ]);
    }
}
