<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\AssessMagnetActivationJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiWebsocketProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\PriceHistory;

class GetBinancePricesCommand extends Command
{
    protected $signature = 'mjolnir:get-binance-prices';

    protected $description = 'Fetches the exchange symbols prices each second';

    public ApiDataMapperProxy $dataMapper;

    public function handle()
    {
        $this->dataMapper = new ApiDataMapperProxy('binance');

        // Load credentials and initialize WebSocket proxy using ApiSystem canonical
        $account = Account::admin('binance');
        $credentials = new ApiCredentials($account->credentials);

        // Instantiate the WebSocket proxy dynamically based on the ApiSystem canonical
        $websocketProxy = new ApiWebsocketProxy('binance', $credentials);

        // Define WebSocket callbacks
        $callbacks = [
            'message' => function ($conn, $msg) {
                $decoded = json_decode($msg, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Log::error('Invalid JSON received in Binance prices message.', ['msg' => $msg]);
                    return;
                }

                $prices = collect($decoded);

                $currentTime = now();
                // Check if it's the start of a new minute
                $its1minute = $currentTime->second === 0;

                // Check if it's the start of a new 5-minute interval
                $its5minutes = $its1minute && $currentTime->minute % 5 === 0;

                // Check if the current second is a multiple of 5
                $its5seconds = $currentTime->second % 5 === 0;

                // Reformat prices: 'BTCUSDT' => 110510, ...
                $prices = $prices->pluck('p', 's')->all();

                // Update exchange symbol prices each second
                $this->savePricesOnExchangeSymbols($prices);

                if ($its1minute) {
                    echo 'Prices statuses OK at ' . $currentTime . PHP_EOL;
                }

                if ($its5seconds) {
                    $this->savePricesOnPositions();
                }

                if ($its5minutes) {
                    $this->savePricesOnExchangeSymbolsHistory($prices);
                }
            },

            'ping' => function ($conn, $msg) {
                // Optionally handle ping messages if necessary.
            },
        ];

        $websocketProxy->markPrices($callbacks);
    }

    public function savePricesOnExchangeSymbols(array $prices)
    {
        // Use chunking to avoid loading all records at once.
        ExchangeSymbol::chunk(100, function ($exchangeSymbols) use ($prices) {
            foreach ($exchangeSymbols as $exchangeSymbol) {
                $pair = $exchangeSymbol->parsedTradingPair('binance');

                if (isset($prices[$pair])) {
                    $exchangeSymbol->update([
                        'last_mark_price' => $prices[$pair],
                        'price_last_synced_at' => now(),
                    ]);
                }
            }
        });
    }

    public function savePricesOnPositions()
    {
        echo "Running savePricesOnPositions() at " . now() . PHP_EOL;

        // Use chunking to handle large sets of positions.
        Position::with('exchangeSymbol')
            ->opened()
            ->chunkById(100, function ($positions) {
                foreach ($positions as $position) {
                    $position->last_mark_price = $position->exchangeSymbol->last_mark_price;
                    $position->save();

                    // Queue job to assess magnet activation.
                    CoreJobQueue::create([
                        'class' => AssessMagnetActivationJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $position->id,
                        ],
                    ]);
                }
            });
    }

    public function savePricesOnExchangeSymbolsHistory(array $prices)
    {
        // Use chunking to prevent memory issues with large datasets.
        ExchangeSymbol::chunk(100, function ($exchangeSymbols) use ($prices) {
            foreach ($exchangeSymbols as $exchangeSymbol) {
                $pair = $exchangeSymbol->parsedTradingPair('binance');

                if (isset($prices[$pair])) {
                    PriceHistory::create([
                        'exchange_symbol_id' => $exchangeSymbol->id,
                        'mark_price' => $prices[$pair],
                    ]);
                }
            }
        });
    }
}
