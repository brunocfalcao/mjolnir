<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

trait MapsQueryIndicators
{
    public function prepareQueryIndicatorsProperties(ExchangeSymbol $exchangeSymbol, string $timeframe): ApiProperties
    {
        $properties = new ApiProperties;

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        $symbol = $apiDataMapper->baseWithQuote(
            $exchangeSymbol->symbol->token,
            $exchangeSymbol->quote->canonical
        );

        $properties->set('options.symbol', $symbol);
        $properties->set('options.interval', $timeframe);
        $properties->set('options.exchange', $exchangeSymbol->apiSystem->taapi_canonical);
        $properties->set('options.indicators', $this->getIndicatorsListForApi());

        return $properties;
    }

    public function resolveQueryIndicatorsResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }

    protected function getIndicatorsListForApi(): array
    {
        $enrichedIndicators = [];

        foreach (Indicator::active()->where('is_apiable', true)->get() as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicatorInstance = new $indicatorClass;

            $parameters = $indicatorModel->parameters ?? [];

            $enrichedIndicator = array_merge(
                [
                    'id' => $indicatorModel->canonical,
                    'indicator' => $indicatorInstance->endpoint,
                ],
                $parameters
            );

            $enrichedIndicators[] = $enrichedIndicator;
        }

        return $enrichedIndicators;
    }
}
