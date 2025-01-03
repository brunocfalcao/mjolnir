<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    public function prepareQueryMarkPriceProperties(string $tradingPair): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $tradingPair);

        return $properties;
    }

    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $data = json_decode($response->getBody(), true);

        if (array_key_exists('markPrice', $data)) {
            return $data['markPrice'];
        }

        return null;
    }
}
