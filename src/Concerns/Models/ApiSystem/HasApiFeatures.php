<?php

namespace Nidavellir\Mjolnir\Concerns\Models\ApiSystem;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Thor\Models\Account;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount(Account $account)
    {
        return $account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    // Queries an order.
    public function apiQueryMarketData(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->getExchangeInformation($this->apiProperties);

        dd('here');

        return $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse);
    }
}
