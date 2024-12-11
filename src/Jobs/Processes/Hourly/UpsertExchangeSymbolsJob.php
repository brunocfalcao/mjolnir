<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Quote;
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
         * 1. We will iterate each symbol on the market data.
         * 2. We will find that symbol in the market data.
         * 3. We will create the symbol on each quote (even if we don't use them).
         * 4. We will upsert data from the market and from the leverage.
         */
        foreach ($parsedMarketData as $pair => $exchangeTradingPair) {
            $tradingPair = $this->dataMapper->identifyBaseAndQuote($pair);
            $parsedTradingPair = $this->dataMapper->baseWithQuote($tradingPair['base'], $tradingPair['quote']);

            // Check if we have that symbol on our database.
            $symbol = Symbol::firstWhere('token', $tradingPair['base']);
            $quote = Quote::firstWhere('canonical', $tradingPair['quote']);

            if ($symbol) {
                $data = [
                    'symbol_id' => $symbol->id,
                    'quote_id' => $quote->id,
                    'api_system_id' => $this->apiSystem->id,
                    'is_upsertable' => true,
                    'price_precision' => $exchangeTradingPair['pricePrecision'],
                    'quantity_precision' => $exchangeTradingPair['quantityPrecision'],
                    'min_notional' => $exchangeTradingPair['minNotional'],
                    'tick_size' => $exchangeTradingPair['tickSize'],
                    'symbol_information' => $exchangeTradingPair,
                    'leverage_brackets' => $parsedLeverageData[$parsedTradingPair],
                ];

                $exchangeSymbol = ExchangeSymbol::where('symbol_id', $symbol->id)
                    ->where('quote_id', $quote->id)
                    ->where('api_system_id', $this->apiSystem->id)
                    ->first();

                if ($exchangeSymbol) {
                    $exchangeSymbol->update($data);

                    continue;
                }

                ExchangeSymbol::create([
                    'symbol_id' => $symbol->id,
                    'quote_id' => Quote::firstWhere('canonical', $tradingPair['quote'])->id,
                    'api_system_id' => $this->apiSystem->id,
                    'is_upsertable' => true,
                    'price_precision' => $exchangeTradingPair['pricePrecision'],
                    'quantity_precision' => $exchangeTradingPair['quantityPrecision'],
                    'min_notional' => $exchangeTradingPair['minNotional'],
                    'tick_size' => $exchangeTradingPair['tickSize'],
                ]);
            }
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

        $transformedData = $marketDataCollection->mapWithKeys(function ($symbolData) {
            // Extract the filters array for specific keys
            $filters = collect($symbolData['filters']);

            $minNotional = $filters->firstWhere('filterType', 'MIN_NOTIONAL')['notional'] ?? null;
            $tickSize = $filters->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null;

            return [
                $symbolData['symbol'] => [
                    'pricePrecision' => $symbolData['pricePrecision'],
                    'quantityPrecision' => $symbolData['quantityPrecision'],
                    'tickSize' => $tickSize,
                    'minNotional' => $minNotional,
                ],
            ];
        });

        // Filter out symbols with underscores in their keys
        $filteredData = $transformedData->filter(function ($value, $key) {
            return strpos($key, '_') === false;
        });

        // Convert the filtered collection to an array
        return $filteredData->toArray();
    }
}
