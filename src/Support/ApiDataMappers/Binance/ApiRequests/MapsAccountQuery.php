<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveQueryAccountResponse(Response $response): array
    {
        $response = json_decode($response->getBody(), true);

        if (array_key_exists('assets', $response)) {
            unset($response['assets']);
        }

        if (array_key_exists('positions', $response)) {
            unset($response['positions']);
        }

        return $response;
    }
}
