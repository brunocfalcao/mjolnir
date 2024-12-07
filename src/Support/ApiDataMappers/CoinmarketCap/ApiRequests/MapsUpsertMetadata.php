<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Symbol;

trait MapsUpsertMetadata
{
    public function prepareUpsertMetadataProperties(Symbol $symbol): ApiProperties
    {
        $properties = new ApiProperties;

        $properties->set('options.id', $this->cmc_id);

        return $properties;
    }

    public function resolveUpsertMetadataProperties(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        $this->validateUpsertMetadata($data);

        return $data;
    }

    public function validateUpsertMetadata(array $data)
    {
        $rules = [
            'order_id' => 'required|integer',
            'symbol' => 'required|array|size:2',
            'symbol.0' => 'required|string',
            'symbol.1' => 'required|string',
            'status' => 'required|string|in:NEW,FILLED,PARTIALLY_FILLED,CANCELLED',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric',
            'type' => 'required|string|in:LIMIT,MARKET',
            'side' => 'required|string|in:SELL,BUY',
        ];

        $this->validate($data, $rules);
    }
}
