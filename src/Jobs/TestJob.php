<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Order;

class TestJob extends BaseQueuableJob
{
    public $orderId;

    public $positionId;

    public function __construct($orderId, $positionId)
    {
        $this->orderId = $orderId;
        $this->positionId = $positionId;
    }

    protected function compute()
    {
        $dataMapper = new ApiDataMapperProxy('binance');

        return Order::find(1)->apiQuery(); // Right one: 29917820287
    }
}
