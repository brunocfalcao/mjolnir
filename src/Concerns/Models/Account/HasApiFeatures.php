<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Account;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiRESTProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\User;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    // Queries the trade data for this position.
    public function apiQuery()
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryAccountProperties();

        $this->apiResponse = $this->withApi()->account($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryAccountResponse($this->apiResponse)
        );
    }

    // Returns the account that has a specific api system canonical from an admin user.
    public static function admin(string $apiSystemCanonical)
    {
        $userAdmin = User::firstWhere('is_admin', true);
        $apiSystem = ApiSystem::firstWhere('canonical', $apiSystemCanonical);

        return Account::where(
            'user_id',
            $userAdmin->id
        )->where(
            'api_system_id',
            $apiSystem->id
        )->first();
    }

    /**
     * Return the right api client object from the ApiRESTProxy given the account
     * connection id.
     */
    public function withApi()
    {
        return new ApiRESTProxy(
            $this->apiSystem->canonical,
            /**
             * The credentials stored on the exchange connection are always
             * encrypted, so no need to re-encrypt them again.
             */
            new ApiCredentials(
                $this->credentials
            )
        );
    }

    public function apiQueryPositions(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryPositionsProperties();
        $this->apiResponse = $this->withApi()->getPositions($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryPositionsResponse($this->apiResponse)
        );
    }

    // Returns the full wallet balance and respective attributes.
    public function apiQueryAccount(): ApiResponse
    {
    }

    // Returns balance per account trading pair.
    public function apiQueryBalance(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareGetBalanceProperties($this);
        $this->apiResponse = $this->withApi()->getAccountBalance($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveGetBalanceResponse($this->apiResponse)
        );
    }
}
