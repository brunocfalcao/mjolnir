<?php

namespace Nidavellir\Mjolnir\Support\Apis\REST;

use Nidavellir\Mjolnir\Concerns\HasPropertiesValidation;
use Nidavellir\Mjolnir\Support\ApiClients\REST\TaapiApiClient;
use Nidavellir\Mjolnir\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\ValueObjects\ApiRequest;
use Illuminate\Support\Facades\Crypt;

/**
 * TaapiApi handles the communication with the Taapi.io API,
 * allowing retrieval of indicator values for specific symbols.
 */
class TaapiApi
{
    use HasPropertiesValidation;

    // API client instance.
    protected $client;

    // Decrypted API secret key.
    protected $secret;

    // Constructor to initialize the API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->secret = Crypt::decrypt($credentials->get('secret'));

        $this->client = new TaapiApiClient([
            'url' => 'https://api.taapi.io',
            'secret' => $this->secret,
        ]);
    }

    // Fetches indicator values for the given API properties.
    public function getIndicatorValues(ApiProperties $properties)
    {
        $payload = [
            'secret' => $this->secret,
            'construct' => [
                'exchange' => $properties->get('options.exchange'),
                'symbol' => $properties->get('options.symbol'),
                'interval' => $properties->get('options.interval'),
                'indicators' => $properties->get('options.indicators'),
            ],
        ];

        $apiRequest = ApiRequest::make(
            'POST',
            '/bulk',
            new ApiProperties($payload)
        );

        return $this->client->publicRequest($apiRequest);
    }
}