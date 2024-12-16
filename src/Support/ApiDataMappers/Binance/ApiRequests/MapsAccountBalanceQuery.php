<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveGetBalanceResponse(Response $response): array
    {
        return collect(json_decode($response->getBody(), true))->keyBy('asset')->toArray();
    }
}
