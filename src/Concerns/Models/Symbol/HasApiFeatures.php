<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Symbol;

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
        return new ApiDataMapperProxy($this->apiAccount->apiSystem->canonical);
    }

    public function apiSyncMarketData(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareSyncMarketDataProperties($this);
        $this->apiResponse = $this->apiAccount->withApi()->getSymbolsMetadata($this->apiProperties);

        $result = json_decode($this->apiResponse->getBody(), true);

        // Sync symbol.
        $marketData = collect($result['data'])->first();

        if ($marketData) {
            $this->update([
                'name' => $marketData['name'],
                'category' => $marketData['category'],
                'description' => $marketData['description'],
                'image_url' => $marketData['logo'],
                'website' => $this->sanitizeWebsiteAttribute($marketData['urls']['website']),
            ]);
        }

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveSyncMarketDataResponse($this->apiResponse)
        );
    }

    protected function sanitizeWebsiteAttribute(mixed $website): string
    {
        return is_array($website) ? collect($website)->first() : $website;
    }
}