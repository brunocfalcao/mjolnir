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

                dd($prices[0]['p'], $prices[0]['s']);
            },

            'ping' => function ($conn, $msg) {},
        ];

        $websocketProxy->markPrices($callbacks);
    }
}
