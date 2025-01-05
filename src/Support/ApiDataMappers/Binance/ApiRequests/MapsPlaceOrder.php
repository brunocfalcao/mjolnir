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
            case 'PROFIT':
            case 'LIMIT':
                $properties->set('options.timeinforce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', $order->price);
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

    public function resolvePlaceOrderResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
