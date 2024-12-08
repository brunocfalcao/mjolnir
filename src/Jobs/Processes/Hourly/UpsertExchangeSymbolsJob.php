<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\TradingPair;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

class UpsertExchangeSymbolsJob extends BaseQueuableJob
{
    public int $apiSystemId;

    public ApiSystem $apiSystem;

    public ApiDataMapperProxy $dataMapper;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystemId = $apiSystemId;

        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);

        $this->dataMapper = new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    public function compute()
    {
        $marketDataCoreJobQueue = $this->coreJobQueue->getByCanonical('market-data:' . $this->apiSystem->canonical)->first();

        $exchangeInformation = $marketDataCoreJobQueue->response;

        $marketData = json_decode($exchangeInformation, true)['body'];

        $parsedData = $this->parseMarketData($marketData);

        dd($parsedData);
    }

    public function parseMarketData(array $marketData)
    {
        // Transform the array into a Laravel collection for easier manipulation
        $marketDataCollection = collect($marketData['symbols']);

        $transformedData = $marketDataCollection->map(function ($symbolData) {
            // Extract the filters array for specific keys
            $filters = collect($symbolData['filters']);

            $minNotional = $filters->firstWhere('filterType', 'MIN_NOTIONAL')['notional'] ?? null;
            $tickSize = $filters->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

            return [
                'symbol' => $symbolData['symbol'],
                'price_precision' => $symbolData['pricePrecision'],
                'quantity_precision' => $symbolData['quantityPrecision'],
                'min_notional' => $minNotional,
                'tick_size' => $tickSize,
                'token_information' => $symbolData, // Include all inner data of the symbol
            ];
        });

        // Convert the collection back to an array
        $finalArray = $transformedData->toArray();

        return $finalArray;
    }
}
