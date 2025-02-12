<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Order;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Thor\Models\OrderHistory;
use Nidavellir\Thor\Models\User;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        $this->load('position.account');

        return $this->position->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    public function apiModify(?float $quantity = null, ?float $price = null)
    {
        if (! $quantity) {
            $quantity = $this->quantity;
        }

        if (! $price) {
            $price = $this->price;
        }

        $this->apiProperties = $this->apiMapper()->prepareOrderModifyProperties($this, $quantity, $price);
        $this->apiResponse = $this->apiAccount()->withApi()->modifyOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderModifyResponse($this->apiResponse)
        );
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
        $apiResponse = $this->apiQuery();

        $this->update([
            'status' => $apiResponse->result['status'],
            'quantity' => $apiResponse->result['quantity'],
            'price' => $apiResponse->result['price'],
            'api_result' => $apiResponse->result,
        ]);

        // Add Order to the order history.
        try {
            OrderHistory::create(array_merge([
                'order_id' => $this->id,
            ], $apiResponse->result['_raw']));
        } catch (\Throwable $e) {
            User::admin()->get()->each(function ($user) use ($e) {
                $user->pushover(
                    message: 'Order history error: '.$e->getMessage(),
                    title: 'Order history saving error',
                    applicationKey: 'nidavellir_errors'
                );
            });
        }

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

    public function apiCancel(): ApiResponse
    {
        /**
         * Cancels an order. Mostly used for scenarios where we need to cancel
         * the profit order (take-profit order), and recreate a new one due
         * to a new WAP calculation.
         */
    }
}
