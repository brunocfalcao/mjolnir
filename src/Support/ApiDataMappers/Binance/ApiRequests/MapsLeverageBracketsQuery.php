<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    public function prepareQueryLeverageBracketsDataProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
