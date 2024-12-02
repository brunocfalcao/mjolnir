<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;

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

        if (rand(1, 100) > 50) {
            throw new \Exception('Raised exception on position id '.$this->positionId);
        }

        // Simulate some processing time (e.g., API call)
        sleep(rand(1, 3)); // Simulated delay
        info('Job processed');
    }
}
