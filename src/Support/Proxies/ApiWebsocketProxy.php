<?php

namespace Nidavellir\Mjolnir\Support\Proxies;

use Nidavellir\Mjolnir\Support\Apis\Websocket\BinanceApi;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;

class ApiWebsocketProxy
{
    protected $api;

    public function __construct(string $apiType, ApiCredentials $credentials)
    {
        // Instantiate appropriate WebSocket API class based on the API type
        switch ($apiType) {
            case 'binance':
                $this->api = new BinanceApi($credentials);
                break;
            default:
                throw new \Exception("Unsupported WebSocket API: {$apiType}");
        }
    }

    /**
     * Magic method to dynamically call methods on the specific WebSocket API class.
     */
    public function __call($method, $arguments)
    {
        // Check if the method exists on the instantiated WebSocket API class
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new \Exception("Method {$method} does not exist for this WebSocket API.");
    }
}
