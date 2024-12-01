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

    public function getApiCanonical(): string
    {
        return
            $this->position
                ->account
                ->apiSystem
                ->canonical;
    }

    public function apiQuery2()
    {
        /*
        $dataMapper = new ApiDataMapperProxy($this->getApiCanonical());
        $properties = $dataMapper->prepareOrderQuery($this);
        $response = $this->position->account->withApi()->orderQuery($properties);
        */

        return $dataMapper->resolveOrderQuery($response);
    }

    public function ApiCall(callable $callable) {}
}
