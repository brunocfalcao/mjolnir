<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ExceptionStubProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Order;

class TestJob extends BaseApiableJob
{
    public $orderId;

    public $positionId;

    public function __construct($orderId, $positionId)
    {
        $this->orderId = $orderId;
        $this->positionId = $positionId;

        $this->rateLimiter = RateLimitProxy::make('binance');
        $this->exceptionHandler = BaseApiExceptionHandler::make('binance');
    }

    public function computeApiable()
    {
        $dataMapper = new ApiDataMapperProxy('binance');

        if ($this->coreJobQueue->id == rand(1, 10)) {
            $binanceStub = ExceptionStubProxy::create('binance');
            throw $binanceStub->simulateUnauthorizedError();
        }

        return Order::find(1)->apiQuery(); // Right one: 29917820287
    }
}
