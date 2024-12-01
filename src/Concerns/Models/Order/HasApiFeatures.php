<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        return $this->position->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    // Queries an order.
    public function apiQuery(): array
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderQueryProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->orderQuery($this->apiProperties);

        return $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse);
    }

    // Places the order given the order model attributes.
    public function apiPlace(): array
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderPlaceProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->orderPlace($this->apiProperties);

        return $this->apiMapper()->resolveOrderPlaceResponse($this->apiResponse);
    }
}
