<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
