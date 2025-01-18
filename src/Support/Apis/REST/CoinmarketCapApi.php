<?php

namespace Nidavellir\Mjolnir\Support\Apis\REST;

use Nidavellir\Mjolnir\Support\ApiClients\REST\CoinmarketCapApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;

class CoinmarketCapApi
{
    protected $client;

    // Initializes CoinMarketCap API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new CoinmarketCapApiClient([
            'url' => 'https://pro-api.coinmarketcap.com',

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('api_key'),
        ]);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV2CryptocurrencyInfo
    public function getSymbolsMetadata(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/info?',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV1CryptocurrencyMap
    public function getSymbols(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/map',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }
}
