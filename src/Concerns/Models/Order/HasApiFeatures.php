<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;

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
    public function apiQuery(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderQueryProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->orderQuery($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse)
        );
    }

    // Syncs an order. Gets data from the server and updates de order. Triggers the observer.
    public function apiSync(): ApiResponse
    {
        info('[apiSync] - Starting sync of Order ID '.$this->id);

        $apiResponse = $this->apiQuery();

        info('[apiSync] - Order ID '.$this->id.', Result: '.json_encode($apiResponse->result));

        $this->changeToSyncing();

        $this->update([
            'status' => $apiResponse->result['status'],
            'quantity' => $apiResponse->result['quantity'],
            'price' => $apiResponse->result['price'],
            'api_result' => $apiResponse->result,
        ]);

        $this->changeToSynced();

        return $apiResponse;
    }

    // Places an order.
    public function apiPlace(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceOrderProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->placeOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlaceOrderResponse($this->apiResponse)
        );

        $this->update([
            'exchange_order_id' => $finalResponse->result['orderId'],
            'started_at' => now(),
        ]);

        return $finalResponse;
    }
}
