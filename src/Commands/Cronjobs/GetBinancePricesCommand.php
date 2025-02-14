<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\AssessMagnetActivationJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiWebsocketProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\PriceHistory;

class GetBinancePricesCommand extends Command
{
    protected $signature = 'mjolnir:get-binance-prices';

    protected $description = 'Fetches the exchange symbols prices each second';

    public ApiDataMapperProxy $dataMapper;

    public ApiSystem $apiSystem;

    public string $canonical;

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
                $prices = collect(json_decode($msg, true));

                // Check if it's the start of a new minute
                $its1minute = now()->second === 0;

                // Check if it's the start of a new 5-minute interval
                $its5minutes = $its1minute && now()->minute % 5 === 0;

                // Readjust prices with a new array like 'BTCUSDT' => 110510, ...
                $prices = collect($prices)->pluck('p', 's')->all();

                $this->savePricesOnExchangeSymbolAndPositions($prices);

                if ($its1minute) {
                    echo 'Prices statuses OK at '.now().PHP_EOL;
                }

                if ($its5minutes) {
                    $this->savePricesOnExchangeSymbolsHistory($prices);
                }
            },

            'ping' => function ($conn, $msg) {
            },
        ];

        $websocketProxy->markPrices($callbacks);
    }

    public function savePricesOnExchangeSymbolAndPositions(array $prices)
    {
        ExchangeSymbol::all()->each(function ($exchangeSymbol) use ($prices) {

            $pair = $exchangeSymbol->parsedTradingPair('binance');

            if (array_key_exists($pair, $prices)) {
                // Save price on the exchange_symbols table.
                $exchangeSymbol->update([
                    'last_mark_price' => $prices[$pair],
                    'price_last_synced_at' => now(),
                ]);

                Position::opened()
                    ->where('exchange_symbol_id', $exchangeSymbol->id)
                    ->get()
                    ->each(function ($position) use ($exchangeSymbol) {
                        $position->last_mark_price = $exchangeSymbol->last_mark_price;
                        $position->save();

                        // Assess if we need to activate the magnetization.
                        CoreJobQueue::create([
                            'class' => AssessMagnetActivationJob::class,
                            'queue' => 'positions',
                            'arguments' => [
                                'positionId' => $position->id,
                            ],
                        ]);

                        // $position->assessMagnetActivation();
                        // Assess if we need to trigger the magnetization.
                        // $position->assessMagnetTrigger();
                    });
            }
        });
    }

    public function savePricesOnExchangeSymbolsHistory(array $prices)
    {
        ExchangeSymbol::all()->each(function ($exchangeSymbol) use ($prices) {

            $pair = $exchangeSymbol->parsedTradingPair('binance');

            if (array_key_exists($pair, $prices)) {
                // Save price on the price history.
                PriceHistory::create([
                    'exchange_symbol_id' => $exchangeSymbol->id,
                    'mark_price' => $prices[$pair],
                ]);
            }
        });
    }
}
