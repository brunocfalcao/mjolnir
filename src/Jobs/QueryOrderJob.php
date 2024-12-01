<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\ApiableJob;
use Nidavellir\Thor\Models\Order;

class QueryOrderJob extends ApiableJob
{
    protected function compute()
    {
        // Simulate querying an API using parameters
        /*
        $parameters = $this->apiJob->parameters;
        $response = [
            'order_id' => $parameters['order_id'],
            'status' => 'filled',
        ];
        */

        // Simulate some processing time (e.g., API call)
        sleep(rand(1, 3)); // Simulated delay

        return Order::find(1)->apiQuery();

        //return $response;
    }
}
