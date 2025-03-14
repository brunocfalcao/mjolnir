<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Order;

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
        $properties->set('options.side', $this->sideType($order->side));
        $properties->set('options.quantity', (string) $order->quantity);

        switch ($order->type) {
            // A profit order is a market stop order.
            case 'PROFIT':
                $properties->set('options.reduceonly', 'true');
                $properties->set('options.timeinforce', 'GTC');
                $properties->set('options.type', 'TAKE_PROFIT_MARKET');
                $properties->set('options.stopPrice', $order->price);
                $properties->set('options.closePosition', 'true');
                break;

            case 'LIMIT':
                $properties->set('options.timeinforce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', $order->price);
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.type', 'MARKET');
                break;

            case 'STOP-MARKET':
                $properties->set('options.type', 'STOP_MARKET');
                $properties->set('options.timeinforce', 'GTC');
                $properties->set('options.closePosition', 'true');
                $properties->set('options.stopPrice', $order->price);
                break;
        }

        return $properties;
    }

    public function resolvePlaceOrderResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
