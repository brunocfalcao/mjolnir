<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\Requests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Order;

trait OrderQuery
{
    /**
     * Prepares data to call the query order.
     * COMPLETED.
     */
    public function prepareOrderQuery(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $order->position->parsed_trading_pair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    public function resolveOrderQuery(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        $price = $result['avgPrice'] != 0 ? $result['avgPrice'] : $result['price'];
        $quantity = $result['executedQty'] != 0 ? $result['executedQty'] : $result['origQty'];

        if ($result['status'] == 'CANCELED') {
            $result['status'] = 'CANCELLED';
        }

        $data = [
            // Exchange order id.
            'order_id' => $result['orderId'],

            // [0 => 'RENDER', 1 => 'USDT']
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),

            // NEW, FILLED, CANCELED, PARTIALLY_FILLED
            'status' => $result['status'],

            'price' => $price,
            'quantity' => $quantity,
            'type' => $result['type'],
            'side' => $result['side'],
        ];

        $this->validateOrderQuery($data);

        return $data;
    }
}
