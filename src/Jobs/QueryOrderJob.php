<?php

namespace Nidavellir\Mjolnir\Jobs;

use Nidavellir\Mjolnir\Abstracts\ApiableJob;

class QueryOrderJob extends ApiableJob
{
    protected function compute()
    {
        // Simulate querying an API using parameters
        $parameters = $this->apiJob->parameters;
        $response = [
            'order_id' => $parameters['order_id'],
            'status' => 'filled',
        ];

        // Simulate some processing time (e.g., API call)
        sleep(2); // Simulated delay

        return $response;
    }

    protected function getResponse()
    {
        return $this->response;
    }
}
