<?php

namespace Nidavellir\Mjolnir\Support\Proxies;

use Nidavellir\Mjolnir\Support\Apis\REST\AlternativeMeApi;
use Nidavellir\Mjolnir\Support\Apis\REST\BinanceApi;
use Nidavellir\Mjolnir\Support\Apis\REST\CoinmarketCapApi;
use Nidavellir\Mjolnir\Support\Apis\REST\TaapiApi;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;

class ApiProxy
{
    protected $api;

    public function __construct(string $apiType, ?ApiCredentials $credentials = null)
    {
        // Instantiate Nidavellir\Mjolnir\ropriate API class based on the API type
        switch ($apiType) {
            case 'binance':
                $this->api = new BinanceApi($credentials);
                break;
            case 'taapi':
                $this->api = new TaapiApi($credentials);
                break;
            case 'coinmarketcap':
                $this->api = new CoinmarketCapApi($credentials);
                break;
            case 'alternativeme':
                $this->api = new AlternativeMeApi;
                break;
            default:
                throw new \Exception('Unsupported API: '.$apiType);
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
