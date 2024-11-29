<?php

namespace Nidavellir\Mjolnir\Support\ApiClients\REST;

use Nidavellir\Mjolnir\Abstracts\BaseApiClient;
use Nidavellir\Mjolnir\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\ValueObjects\ApiRequest;

class TaapiApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $credentials = ApiCredentials::make([
            'secret' => $config['secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest, true);
    }
}
