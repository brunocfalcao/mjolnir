<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareOrderQueryProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveOrderQueryResponse(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        dd('ok');
    }
}
