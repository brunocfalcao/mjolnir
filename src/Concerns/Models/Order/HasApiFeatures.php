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
        $this->apiResponse = $this->apiAccount()->withApi()->placeOrder($this->apiProperties);

        return $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse);
    }

    // Syncs an order. Gets data from the server and updates de order. Triggers the observer.
    public function apiSync(): void
    {
        $result = $this->apiQuery();

        $this->update([
            'exchange_order_id' => $result['order_id'],
        ]);

        /*
        return [
            'order_id' => $result['orderId'],
            'average_price' => $result['avgPrice'],
            'price' => $result['price'],
            'original_quantity' => $result['origQty'],
            'executed_quantity' => $result['executedQty'],
        ];
        */
    }

    // Places an order.
    public function apiPlace(): array
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceOrderProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->placeOrder($this->apiProperties);

        return $this->apiMapper()->resolvePlaceOrderResponse($this->apiResponse);
    }
}
