<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order\ApiRequests;

use Nidavellir\Mjolnir\Support\ApiDataMappers\DataMapperValidator;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

trait OrderQuery
{
    use DataMapperValidator;

    public function apiQuery(): array
    {
        $account = $this->position->account;

        $dataMapper = new ApiDataMapperProxy($account->apiSystem->canonical);
        $properties = $dataMapper->prepareOrderQueryProperties($this);
        $response = $account->withApi()->orderQuery($properties);

        return $dataMapper->resolveOrderQueryResponse($response);
    }
}
