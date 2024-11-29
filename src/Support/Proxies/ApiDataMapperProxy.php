<?php

namespace Nidavellir\Mjolnir\Support\Proxies;

use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Bybit\BybitApiDataMapper;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Coinbase\CoinbaseApiDataMapper;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\TaapiApiDataMapper;

class ApiDataMapperProxy
{
    protected $api;

    public function __construct(string $apiCanonical)
    {
        // Instantiate appropriate API class based on the API type
        switch ($apiCanonical) {
            case 'binance':
                $this->api = new BinanceApiDataMapper;
                break;
            case 'taapi':
                $this->api = new TaapiApiDataMapper;
                break;
            case 'coinmarketcap':
                $this->api = new CoinbaseApiDataMapper;
                break;
            case 'bybit':
                $this->api = new BybitApiDataMapper;
                break;
            default:
                throw new \Exception('Unsupported API Mapper: '.$apiCanonical);
        }
    }

    /**
     * Magic method to dynamically call methods on the specific API class.
     */
    public function __call($method, $arguments)
    {
        // Check if the method exists on the instantiated API class
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new \Exception("Method {$method} does not exist for this API.");
    }
}
