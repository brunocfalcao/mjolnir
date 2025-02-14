<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\PriceHistory;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Mjolnir\Support\Proxies\ApiWebsocketProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\AssessMagnetActivationJob;
use Nidavellir\Mjolnir\Jobs\Processes\CreateMagnetOrder\CreateMagnetOrderLifecycleJob;

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

                $each1minute = $currentTime->second === 0;
                $each5minutes = $each1minute && $currentTime->minute % 5 === 0;
                $each5seconds = $currentTime->second % 5 === 0;
                $each6seconds = $currentTime->second % 6 === 0;

                // Reformat prices: 'BTCUSDT' => 110510, ...
                $prices = $prices->pluck('p', 's')->all();

                // Update exchange symbol prices each second
                $this->savePricesOnExchangeSymbols($prices);

                if ($each1minute) {
                    echo 'Prices statuses OK at '.$currentTime.PHP_EOL;
                }

                if ($each5seconds) {
                    $this->assessMagnetActivations();
                }

                if ($each6seconds) {
                    $this->assessMagnetTriggers();
                }

                if ($each5minutes) {
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

    public function assessMagnetActivations()
    {
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

    public function assessMagnetTriggers()
    {
        Position::opened()->get()->each(function ($position) {

            $magnetTriggerOrder = $position->assessMagnetTrigger();

            if ($magnetTriggerOrder != null) {
                // We have a position to trigger the magnet.
                CoreJobQueue::create([
                    'class' => CreateMagnetOrderLifecycleJob::class,
                    'queue' => 'orders',
                    'arguments' => [
                        'positionId' => $magnetTriggerOrder->id,
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
