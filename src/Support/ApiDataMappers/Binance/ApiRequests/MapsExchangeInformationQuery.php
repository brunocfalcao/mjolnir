<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode($response->getBody(), true);

        return collect($data['symbols'] ?? [])->map(function ($symbolData) {
            $filters = collect($symbolData['filters'] ?? []);

            return [
                'symbol' => $symbolData['symbol'],
                'pricePrecision' => $symbolData['pricePrecision'],
                'quantityPrecision' => $symbolData['quantityPrecision'],
                'tickSize' => (float) $filters->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null,
                'minNotional' => (float) $filters->firstWhere('filterType', 'MIN_NOTIONAL')['notional'] ?? null,
            ];
        })->toArray();
    }
}
