<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Quote;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradeConfiguration;

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
        // Remove non perpetuals trading pairs.
        $marketData = collect(
            $this->coreJobQueue
                ->getByCanonical('market-data:'.$this->apiSystem->canonical)->first()->response
        )->keyBy('symbol')->filter(function ($value, $key) {
            return strpos($key, '_') === false;
        });

        $leverageData = collect(
            $this->coreJobQueue
                ->getByCanonical('leverage-data:'.$this->apiSystem->canonical)->first()->response
        )->keyBy('symbol');

        /**
         * Time to upsert symbol to be created as an exchange symbol.
         * 1. We will iterate each symbol on the market data.
         * 2. We will find that symbol in the market data.
         * 3. We will create the symbol on each quote (even if we don't use them).
         * 4. We will upsert data from the market and from the leverage.
         */
        foreach ($marketData as $pair => $exchangeTradingPair) {
            $tradingPair = $this->dataMapper->identifyBaseAndQuote($pair);
            $parsedTradingPair = $this->dataMapper->baseWithQuote($tradingPair['base'], $tradingPair['quote']);

            // Check if we have that symbol on our database.
            $symbol = Symbol::firstWhere('token', $tradingPair['base']);
            $quote = Quote::firstWhere('canonical', $tradingPair['quote']);

            if ($symbol) {
                $exchangeSymbol = ExchangeSymbol::updateOrCreate(
                    [
                        'symbol_id' => $symbol->id,
                        'quote_id' => $quote->id,
                        'api_system_id' => $this->apiSystem->id,
                    ],
                    [
                        'trade_configuration_id' => TradeConfiguration::default()->first()->id,
                        'price_precision' => $exchangeTradingPair['pricePrecision'],
                        'quantity_precision' => $exchangeTradingPair['quantityPrecision'],
                        'min_notional' => $exchangeTradingPair['minNotional'],
                        'tick_size' => $exchangeTradingPair['tickSize'],
                        'symbol_information' => $exchangeTradingPair,
                        'leverage_brackets' => $leverageData[$parsedTradingPair],
                    ]
                );

                // Add CoreJobQueue to update indicator data, and to decide trade direction.
                $blockUuid = (string) Str::uuid();

                CoreJobQueue::create([
                    'class' => QueryExchangeSymbolIndicatorJob::class,
                    'queue' => 'cronjobs',

                    'arguments' => [
                        'exchangeSymbolId' => $exchangeSymbol->id,
                        'timeframe' => $exchangeSymbol->tradeConfiguration->indicator_timeframes[0],
                    ],
                    'index' => 1,
                    'block_uuid' => $blockUuid,
                ]);

                CoreJobQueue::create([
                    'class' => AssessExchangeSymbolDirectionJob::class,
                    'queue' => 'cronjobs',

                    'arguments' => [
                        'exchangeSymbolId' => $exchangeSymbol->id,
                    ],
                    'index' => 2,
                    'block_uuid' => $blockUuid,
                ]);
            }
        }
    }
}
