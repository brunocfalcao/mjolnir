<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\ExchangeSymbol;

trait MapsQueryIndicator
{
    public function prepareQueryIndicatorProperties(ExchangeSymbol $exchangeSymbol, array $parameters): ApiProperties
    {
        $properties = new ApiProperties;

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        foreach ($parameters as $key => $value) {
            $properties->set('options.'.$key, $value);
        }

        $exchangeSymbol->load(['symbol', 'quote', 'apiSystem']);

        $base = $exchangeSymbol->symbol->token;
        $quote = $exchangeSymbol->quote->canonical;

        $properties->set('options.symbol', $this->baseWithQuote($base, $quote));
        $properties->set('options.exchange', $exchangeSymbol->apiSystem->taapi_canonical);

        return $properties;
    }

    public function resolveQueryIndicatorResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
