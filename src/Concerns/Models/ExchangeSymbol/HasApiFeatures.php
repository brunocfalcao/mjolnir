<?php

namespace Nidavellir\Mjolnir\Concerns\Models\ExchangeSymbol;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public Account $apiAccount;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount->apiSystem->canonical);
    }

    public function apiMarkPrice()
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderQueryProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->orderQuery($this->apiProperties);

        return $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse);
    }
}
