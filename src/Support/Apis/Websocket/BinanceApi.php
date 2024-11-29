<?php

namespace Nidavellir\Mjolnir\Support\Apis\Websocket;

use Nidavellir\Mjolnir\Support\ApiClients\Websocket\BinanceApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Illuminate\Support\Facades\Crypt;

class BinanceApi
{
    protected BinanceApiClient $client;

    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BinanceApiClient([
            'base_url' => 'wss://fstream.binance.com',
            'api_key' => Crypt::decrypt($credentials->get('api_key')),
            'api_secret' => Crypt::decrypt($credentials->get('api_secret')),
        ]);
    }

    /**
     * Subscribes to the Binance WebSocket stream for mark prices of all symbols.
     *
     * @param  array  $callbacks  An associative array of callbacks keyed by event type.
     * @param  bool  $prefersSlowerUpdate  Whether to use a slower update speed (3 seconds).
     */
    public function markPrices(array $callbacks, bool $prefersSlowerUpdate = false)
    {
        // Choose the stream name based on the slower update preference
        $streamName = $prefersSlowerUpdate ? '!markPrice@arr' : '!markPrice@arr@1s';

        // Pass the array of callbacks to subscribeToStream
        $this->client->subscribeToStream($streamName, $callbacks);
    }
}
