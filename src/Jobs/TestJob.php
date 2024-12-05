<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ExceptionStubProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Order;

class TestJob extends BaseApiableJob
{
    public $orderId;

    public $positionId;

    public function __construct($orderId, $positionId)
    {
        $this->orderId = $orderId;
        $this->positionId = $positionId;

        $this->rateLimiter = RateLimitProxy::make('binance')->withAccount(Account::find(1));

        $this->exceptionHandler = BaseApiExceptionHandler::make('binance');
    }

    public function refresh()
    {
        info('Started running refresh() ['.$this->coreJobQueue->id.']');
        sleep(1);
    }

    public function computeApiable()
    {
        info('Started running computeApiable() ['.$this->coreJobQueue->id.']');

        $dataMapper = new ApiDataMapperProxy('binance');

        if ($this->coreJobQueue->id == 3) {
            info('Triggering Exception on id = 3');
            $binanceStub = ExceptionStubProxy::create('binance');
            throw $binanceStub->simulateRateLimitExceeded();
        }

        return Order::find(1)->apiQuery(); // Right one: 29917820287
    }
}
