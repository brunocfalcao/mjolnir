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

    public Account $apiAccount;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    public function apiQueryMarketData(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this);
        $this->apiResponse = $this->apiAccount->withApi()->getExchangeInformation($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }

    public function apiQueryLeverageBracketsData(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryLeverageBracketsDataProperties();
        $this->apiResponse = $this->apiAccount->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }
}
