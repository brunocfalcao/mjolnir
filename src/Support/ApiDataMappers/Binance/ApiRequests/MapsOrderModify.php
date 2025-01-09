<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    public function prepareOrderModifyProperties($order, $quantity, $price): ApiProperties
    {
        $parsedTradingPair = $order->position->parsedTradingPair;

        $properties = new ApiProperties;
        $properties->set('options.orderId', $order->exchange_order_id);
        $properties->set('options.side', $order->side);
        $properties->set('options.symbol', $parsedTradingPair);
        $properties->set('options.quantity', $quantity);
        $properties->set('options.price', $price);

        return $properties;
    }

    public function resolveOrderModifyResponse(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        return [
            'order_id' => $result['orderId'],
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),
            'status' => $result['status'],
            'price' => $result['price'],
            'average_price' => $result['avgPrice'],
            'original_quantity' => $result['origQty'],
            'executed_quantity' => $result['executedQty'],
            'type' => $result['type'],
            'side' => $result['side'],
            'original_type' => $result['origType'],
        ];
    }
}
