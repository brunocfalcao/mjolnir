<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsQueryTrade
{
    public function prepareQueryTradeProperties(string $tradingPair, string $orderId): ApiProperties
    {
        $properties = new ApiProperties;

        $properties->set('options.symbol', (string) $tradingPair);
        $properties->set('options.orderId', (string) $orderId);

        return $properties;
    }

    public function resolveQueryTradeResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
