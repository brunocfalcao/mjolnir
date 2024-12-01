<?php

namespace Nidavellir\Mjolnir\Support\ApiClients\REST;

use Binance\Util\Url;
use Nidavellir\Mjolnir\Abstracts\BaseApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;
use Nidavellir\Thor\Models\ApiSystem;

class BinanceApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    protected function getHeaders(): array
    {
        return [
            'X-MBX-APIKEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function signRequest(ApiRequest $apiRequest)
    {
        $apiRequest->properties->set(
            'options.timestamp',
            round(microtime(true) * 1000)
        );

        // Set the recvwindow
        $apiRequest->properties->set(
            'options.recvWindow',
            ApiSystem::firstWhere('canonical', 'binance')->recvwindow_margin
        );

        $query = Url::buildQuery($apiRequest->properties->getOr('options', []));

        $signature = hash_hmac(
            'sha256',
            $query,
            $this->credentials->get('api_secret')
        );

        $apiRequest->properties->set(
            'options.signature',
            $signature
        );

        return $this->processRequest($apiRequest);
    }
}
