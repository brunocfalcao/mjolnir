<?php

namespace Nidavellir\Mjolnir\Support\ValueObjects;

use GuzzleHttp\Psr7\Response;

class ApiResponse
{
    public Response $response;

    public array $resolvedResult;

    public function __construct(Response $response, array $resolvedResult)
    {
        $this->$response = $response;

        $this->resolvedResult = $resolvedResult;
    }
}
