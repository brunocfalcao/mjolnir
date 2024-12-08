<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Symbol;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public Account $apiAccount;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount->apiSystem->canonical);
    }

    public function apiSyncMarketData(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareSyncMarketDataProperties($this);
        $this->apiResponse = $this->apiAccount->withApi()->getSymbolsMetadata($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveSyncMarketDataResponse($this->apiResponse)
        );
    }
}
