<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Concerns\Models\Order\ApiRequests\OrderQuery;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait HasApiFeatures
{
    use OrderQuery;

    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount(): string
    {
        return $this->position->account;
    }

    public function apiQuery(): array
    {
        $dataMapper = new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
        $properties = $dataMapper->prepareOrderQueryProperties($this);
        $response = $this->apiAccount()->withApi()->orderQuery($properties);

        return $dataMapper->resolveOrderQueryResponse($response);
    }
}
