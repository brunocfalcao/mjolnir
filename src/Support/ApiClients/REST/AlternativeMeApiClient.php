<?php

namespace Nidavellir\Mjolnir\Support\ApiClients\REST;

use Nidavellir\Mjolnir\Abstracts\BaseApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;

class AlternativeMeApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        parent::__construct($config['url'], null);
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }
}
