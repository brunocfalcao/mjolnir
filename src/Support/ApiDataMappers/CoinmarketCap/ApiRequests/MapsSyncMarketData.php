<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Symbol;

trait MapsSyncMarketData
{
    public function prepareSyncMarketDataProperties(Symbol $symbol): ApiProperties
    {
        dd('inside');

        $properties = new ApiProperties;

        $properties->set('options.id', $this->cmc_id);

        return $properties;
    }

    public function resolveSyncMarketDataProperties(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        $this->validateUpsertMetadata($data);

        return $data;
    }
}
