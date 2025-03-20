<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Collection;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

trait MapsGroupedQueryIndicators
{
    public function prepareGroupedQueryIndicatorsProperties(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): ApiProperties
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
        $properties->set('options.indicators', $this->getIndicatorsListForApi($exchangeSymbol, $indicators, $timeframe));

        return $properties;
    }

    public function resolveGroupedQueryIndicatorsResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }

    protected function getIndicatorsListForApi(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): array
    {
        $enrichedIndicators = [];

        foreach ($indicators as $indicatorModel) {
            // Instanciate indicator to retrieve the right db parameters.
            $indicatorClass = $indicatorModel->class;
            $indicatorInstance = new $indicatorClass($exchangeSymbol, ['interval' => $timeframe]);

            $parameters = $indicatorModel->parameters ?? [];

            $enrichedIndicator = array_merge(
                [
                    'id' => $indicatorModel->canonical,
                    'indicator' => $indicatorInstance->endpoint,
                ],
                $indicatorInstance->parameters
            );

            $enrichedIndicators[] = $enrichedIndicator;
        }

        return $enrichedIndicators;
    }
}
