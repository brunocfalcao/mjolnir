<?php

namespace Nidavellir\Mjolnir\Concerns\Models\TradeConfiguration;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public Account $apiAccount;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    public function apiFngQuery(): ApiResponse
    {
        $this->apiAccount = Account::admin('alternativeme');

        $this->apiProperties = new ApiProperties;
        $this->apiResponse = $this->apiAccount->withApi()->getFearAndGreedIndex($this->apiProperties);

        $response = json_decode($this->apiResponse->getBody(), true);

        return new ApiResponse(
            response: $this->apiResponse,
            result: ['fng_index' => $response['data'][0]['value']]
        );
    }
}
