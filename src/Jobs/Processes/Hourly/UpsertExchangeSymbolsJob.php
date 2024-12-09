<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Symbol;

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
        $parsedMarketData = $this->parseMarketData();
        $parsedLeverageData = $this->parseLeverageData();

        /**
         * Time to upsert symbol to be created as an exchange symbol.
         * 1. We will iterate each symbol.
         * 2. We will find that symbol in the market data.
         * 3. We will create the symbol on each quote (even if we don't use them).
         * 4. We will upsert data from the market and from the leverage.
         */

        foreach (Symbol::all() as $symbol) {
        }
    }

    public function parseLeverageData()
    {
        $marketDataCoreJobQueue = $this->coreJobQueue->getByCanonical('leverage-brackets:'.$this->apiSystem->canonical)->first();

        $exchangeInformation = $marketDataCoreJobQueue->response;

        $parsedLeverageData = collect(json_decode($exchangeInformation, true)['body']);

        return $parsedLeverageData->mapWithKeys(function ($data) {
            return [
                $data['symbol'] => collect($data['brackets'])->map(function ($bracket) {
                    return [
                        'max' => $bracket['notionalCap'],
                        'min' => $bracket['notionalFloor'],
                        'leverage' => $bracket['initialLeverage'],
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    public function parseMarketData()
    {
        $marketDataCoreJobQueue = $this->coreJobQueue->getByCanonical('market-data:'.$this->apiSystem->canonical)->first();

        $exchangeInformation = $marketDataCoreJobQueue->response;

        $marketData = json_decode($exchangeInformation, true)['body'];

        // Transform the array into a Laravel collection for easier manipulation
        $marketDataCollection = collect($marketData['symbols']);

        $transformedData = $marketDataCollection->map(function ($symbolData) {
            // Extract the filters array for specific keys
            $filters = collect($symbolData['filters']);

            $minNotional = $filters->firstWhere('filterType', 'MIN_NOTIONAL')['notional'] ?? null;
            $tickSize = $filters->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

            return [
                'symbol' => $symbolData['symbol'],
                'pricePrecision' => $symbolData['pricePrecision'],
                'quantityPrecision' => $symbolData['quantityPrecision'],
                'tickSize' => $tickSize,
                'minNotional' => $minNotional,
            ];
        });

        // Convert the collection back to a JSON string
        return $transformedData->toJson();
    }
}
