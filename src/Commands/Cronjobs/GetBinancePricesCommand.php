<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiWebsocketProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;

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
                echo now().PHP_EOL;

                $prices = collect(json_decode($msg, true));

                // Check if it's the start of a new minute
                $its1minute = now()->second === 0;

                // Check if it's the start of a new 5-minute interval
                $its5minutes = $its1minute && now()->minute % 5 === 0;

                if ($its1minute) {
                    echo now()." (1 minute) - {$prices[0]['s']} {$prices[0]['p']}".PHP_EOL;
                }

                if ($its5minutes) {
                    echo now()." (5 minutes) - {$prices[0]['s']} {$prices[0]['p']}".PHP_EOL;
                }
            },

            'ping' => function ($conn, $msg) {},
        ];

        $websocketProxy->markPrices($callbacks);
    }
}
