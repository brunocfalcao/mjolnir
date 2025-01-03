<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;

class CreateOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public Order $order;

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

    public function computeApiable()
    {
        /**
         * Creating an order obliges a specific sequence. If this order is of
         * type LIMIT, then it can be immediately created. If the order is
         * MARKET, then it needs to be checked first if all the LIMIT
         * orders were already created. If it's a PROFIT then it
         * checks if the MARKET order was created.
         */

        // Limit order? Nothing to verify.
        if ($this->order->type == 'LIMIT') {
            $this->order->apiCreate();
        }

        if ($this->order->type == 'MARKET' && $this->allLimitOrdersCreated()) {
            $this->order->apiCreate();
        }

        if ($this->order->type == 'PROFIT' && $this->marketOrderCreated()) {
            $this->order->apiCreate();
        }
    }

    protected function allLimitOrdersCreated()
    {
        return false;
    }

    protected function marketOrderCreated()
    {
        return false;
    }

    public function resolveException(\Throwable $e)
    {
        // Cancels all open orders (except the market order itself).
        //$this->order->position->apiCancelAllOrders();

        // Opens an opposite market order with same quantity to close position.
        //$this->order->position->apiCancelMarketOrder();

        // Stop the order.
        $this->order->update([
            'status' => 'FAILED',
            'is_syncing' => false,
            'error_message' => $e->getMessage()
        ]);
    }
}
