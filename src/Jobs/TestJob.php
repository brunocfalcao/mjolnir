<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ExceptionStubProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;

class TestJob extends BaseApiableJob
{
    public $orderId;

    public $positionId;

    public function __construct($orderId, $positionId)
    {
        $this->orderId = $orderId;
        $this->positionId = $positionId;

        $this->rateLimiter = RateLimitProxy::make('binance')->withAccount(Account::find(1));
        $this->exceptionHandler = BaseExceptionHandler::make('binance');
    }

    public function computeApiable()
    {
        info('computeApiable()');
        $binanceStub = ExceptionStubProxy::create('binance');
        throw $binanceStub->simulateNoNeedChangeMarginType();
    }

    public function resolveException(\throwable $e)
    {
        info('rolling back transaction!');
    }
}
