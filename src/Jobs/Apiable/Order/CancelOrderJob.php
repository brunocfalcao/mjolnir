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

class CancelOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::with(['position'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;

        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        /**
         * Before we cancel the order, we need to check if the order still
         * exists.
         */
        $this->order->apiSync();

        // Skip observer so the order is not recreated again.
        if ($this->order->status == 'NEW') {
            // info("[CancelOrderJob] - Cancelling order ID {$this->order->id} ({$this->order->type})");
            $this->order->apiCancel();
            $this->order->apiSync();
        }
    }

    public function resolveException(\Throwable $e)
    {
        User::admin()->get()->each(function ($user) use ($e) {
            $user->pushover(
                message: "Error canceling order with ID {$this->order->id}. Error: ".$e->getMessage(),
                title: 'Error canceling order',
                applicationKey: 'nidavellir_errors'
            );
        });
    }
}
