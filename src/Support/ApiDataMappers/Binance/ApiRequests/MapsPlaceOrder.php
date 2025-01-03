<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;

        $symbol = $order->position->exchangeSymbol->symbol;
        $account = $order->position->account;

        $symbol = get_base_token_for_exchange($symbol->token, $account->apiSystem->canonical);
        $parsedSymbol = $this->baseWithQuote($symbol, $account->quote->canonical);

        $properties->set('options.symbol', $parsedSymbol);


        $properties->set('options.side', strtoupper($order->side));
        $properties->set('options.quantity', $order->initial_quantity);

        switch ($order->type) {
            case 'PROFIT':
            case 'LIMIT':
                $properties->set('options.timeinforce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', $order->initial_average_price);
                if ($order->type == 'PROFIT') {
                    $properties->set('options.reduceonly', 'true');
                }
                break;

            case 'MARKET':
            case 'MARKET-CANCEL':
                $properties->set('options.type', 'MARKET');
                break;
        }

        return $properties;
    }

    public function resolvePlaceOrderResponse(Response $response): ?string
    {
        $data = json_decode($response->getBody(), true);

        if (array_key_exists('markPrice', $data)) {
            return $data['markPrice'];
        }

        return null;
    }
}
