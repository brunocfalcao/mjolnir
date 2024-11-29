<?php

namespace Nidavellir\Mjolnir\Support\Apis\REST;

use Nidavellir\Mjolnir\Support\ApiClients\REST\AlternativeMeApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;

class AlternativeMeApi
{
    protected $client;

    public function __construct()
    {
        $this->client = new AlternativeMeApiClient([
            'url' => 'https://api.alternative.me',
        ]);
    }

    public function getFearAndGreedIndex()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fng',
        );

        return $this->client->publicRequest($apiRequest);
    }
}
