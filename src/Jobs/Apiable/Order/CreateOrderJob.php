<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class CreateOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public Order $order;

    // Specific configuration to allow more retry flexibility.
    public int $workerServerBackoffSeconds = 3;

    public int $retries = 10;
    // -----

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
        $this->exchangeSymbol = $this->position->exchangeSymbol;
    }

    public function authorize()
    {
        /**
         * First we create the limit orders, then the market order, then the
         * profit order. We just return the job back on the core job queue
         * to be processed again in a couple of seconds.
         */
        return
            $this->order->type == 'LIMIT' ||
            ($this->order->type == 'MARKET' && $this->allLimitOrdersCreated()) ||
            ($this->order->type == 'PROFIT' && $this->marketOrderCreated());
    }

    public function computeApiable()
    {
        $this->order->apiPlace();
    }

    protected function allLimitOrdersCreated()
    {
        return false;
    }

    protected function marketOrderCreated()
    {
        // Retry the job in 3 seconds.
        return false;
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
