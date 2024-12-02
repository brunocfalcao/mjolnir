<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class QueryOrderJob extends BaseApiableJob
{
    public function __construct(Order $order, Position $position)
    {
        info('all good!');
    }

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
