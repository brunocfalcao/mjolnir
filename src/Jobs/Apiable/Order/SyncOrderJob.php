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

class SyncOrderJob extends BaseApiableJob
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
        $apiResponse = $this->order->apiSync();
    }

    public function resolveException(\Throwable $e)
    {
        /**
         * A sync order job is not a big issue. Something went wrong but it's
         * repeated each minute(s).
         */
        User::admin()->get()->each(function ($user) {
            $user->pushover(
                message: "There was an error trying to sync {$this->position->parsedTradingPair}, order ID {$this->order->id}, it will be retried later",
                title: "Error syncing an order from {$this->position->parsedTradingPair}",
                applicationKey: 'nidavellir_warnings'
            );
        });
    }
}
