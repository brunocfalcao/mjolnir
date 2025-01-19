<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Thor\Models\Order;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        return $this->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    // Cancels all open orders (but not the position if it's open).
    public function apiCancelOrders(): ApiResponse
    {
        $parsedSymbol = $this->parsedTradingPair;

        $this->apiProperties = $this->apiMapper()->prepareCancelOrdersProperties($parsedSymbol);

        $this->apiResponse = $this->apiAccount()->withApi()->cancelAllOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveCancelOrdersResponse($this->apiResponse)
        );
    }

    // Queries the trade data for this position.
    public function apiQueryTrade()
    {
        $parsedSymbol = $this->parsedTradingPair;
        $orderId = $this->orders->firstWhere('type', 'PROFIT')->exchange_order_id;

        $this->apiProperties = $this->apiMapper()->prepareQueryTradeProperties($parsedSymbol, $orderId);

        $this->apiResponse = $this->apiAccount()->withApi()->trade($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryTradeResponse($this->apiResponse)
        );
    }

    // Closes the position (opens a contrary order compared to the position).
    public function apiClose()
    {
        // Get all positions for this position account.
        $apiResponse = $this->account->apiQueryPositions();

        // Get sanitized positions, key = pair.
        $positions = $apiResponse->result;

        if (array_key_exists($this->parsedTradingPair, $positions)) {
            // We have a position. Lets place a contrary order to close it.
            $positionFromExchange = $positions[$this->parsedTradingPair];

            // Place contrary order if amount > 0.
            if ($positionFromExchange['positionAmt'] != 0) {
                $data = [
                    'type' => 'MARKET-CANCEL',
                ];

                // Side is the contrary of the current position side (we check the amount).
                if ($positionFromExchange['positionAmt'] < 0) {
                    $data['side'] = $this->apiMapper()->sideType('BUY');
                } else {
                    $data['side'] = $this->apiMapper()->sideType('SELL');
                }

                $data['quantity'] = abs($positionFromExchange['positionAmt']);
                $data['position_id'] = $this->id;

                $order = Order::create($data);
                $apiResponse = $order->apiPlace();
                $order->apiSync();
            }
        }

        $this->updateToClosed();

        return $apiResponse->response;
    }
}
