<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

trait HasApiFeatures
{
    public function getApiCanonical(): string
    {
        return
            $this->position
                ->account
                ->apiSystem
                ->canonical;
    }

    public function apiQuery()
    {
        $dataMapper = new ApiDataMapperProxy($this->getApiCanonical());

        $properties = $dataMapper->prepareOrderQuery($this);

        $response = $this->position->account->withApi()->orderQuery($properties);

        return $dataMapper->resolveOrderQuery($response);
    }
}
