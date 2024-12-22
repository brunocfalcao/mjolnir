<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Order;

class QueryOrderJob extends BaseApiableJob
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;

        $this->rateLimiter = RateLimitProxy::make('binance')->withAccount(Account::find(1));
        $this->exceptionHandler = BaseExceptionHandler::make('binance');
    }

    public function computeApiable()
    {
        Order::find($this->id)->apiQuery();
    }
}
