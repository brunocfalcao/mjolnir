<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveQueryPositionsResponse(Response $response): array
    {
        return collect(json_decode($response->getBody(), true))->keyBy('symbol')->toArray();
    }
}
