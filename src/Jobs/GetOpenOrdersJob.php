<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\ApiableJob;
use Nidavellir\Thor\Models\ApiJob;

class GetOpenOrdersJob extends ApiableJob
{
    public function compute()
    {
        // Simulate fetching open orders from an API
        $response = ['order1', 'order2', 'order3'];

        // Simulate an error for demonstration
        //throw new \Exception('Ups! Something went wrong.');

        // Save the result to the ApiJob response field
        info('GetOpenOrdersJob completed');

        return $response;
    }
}
