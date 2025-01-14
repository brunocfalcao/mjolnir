<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareServerTimeQueryProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveServerTimeQueryResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
