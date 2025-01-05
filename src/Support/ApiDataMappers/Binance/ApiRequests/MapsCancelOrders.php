<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    public function prepareCancelOrdersProperties(string $tradingPair): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $tradingPair);

        return $properties;
    }

    public function resolveCancelOrdersResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
