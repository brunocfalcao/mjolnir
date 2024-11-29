<?php

namespace Nidavellir\Mjolnir\Support\ApiClients\REST;

use Nidavellir\Mjolnir\Abstracts\BaseApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;

class CoinmarketCapApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    protected function getHeaders(): array
    {
        return [
            'X-CMC_PRO_API_KEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }
}
