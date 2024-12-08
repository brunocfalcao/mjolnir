<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Symbol;

trait MapsSyncMarketData
{
    public function prepareSyncMarketDataProperties(Symbol $symbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.id', $symbol->cmc_id);

        return $properties;
    }

    public function resolveSyncMarketDataResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
