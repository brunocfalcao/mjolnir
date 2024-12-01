<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Order;

trait MapsOrderQuery
{
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;

        $symbol = $order->position->exchangeSymbol->symbol->token;
        $quote = $order->position->exchangeSymbol->quote->canonical;
        $tradingPair = $this->baseWithQuote($symbol, $quote);

        $properties->set('options.symbol', (string) $tradingPair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    public function resolveOrderQueryResponse(Response $response): array
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

    public function validateOrderQuery(array $data)
    {
        $rules = [
            'order_id' => 'required|integer',
            'symbol' => 'required|array|size:2',
            'symbol.0' => 'required|string',
            'symbol.1' => 'required|string',
            'status' => 'required|string|in:NEW,FILLED,PARTIALLY_FILLED,CANCELLED',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric',
            'type' => 'required|string|in:LIMIT,MARKET',
            'side' => 'required|string|in:SELL,BUY',
        ];

        $this->validate($data, $rules);
    }
}
