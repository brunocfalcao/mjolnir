<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

trait HasApiFeatures
{
    public function apiAccount()
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
