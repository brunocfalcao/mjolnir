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

        $properties = $dataMapper->prepareQueryOrderProperties([
            'order_id' => (string) $this->exchange_order_id,
            'symbol' => (string) $dataMapper->baseWithQuote(
                $this->position->exchangeSymbol->symbol->token,
                $this->position->exchangeSymbol->quote->canonical
            ),
        ]);

        return $this->position->account->withApi()->queryOrder($properties);
    }
}
