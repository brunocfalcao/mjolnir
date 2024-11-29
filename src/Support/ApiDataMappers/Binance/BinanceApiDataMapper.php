<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\Requests\OrderQuery;
use Nidavellir\Mjolnir\Support\ApiDataMappers\DataMapperValidator;
use Nidavellir\Thor\Models\Order;

class BinanceApiDataMapper
{
    use DataMapperValidator;
    use OrderQuery;

    /**
     * Returns the well formed base symbol with the quote on it.
     * E.g.: AVAXUSDT. On other cases, for other exchanges, it can
     * return AVAX/USDT (Coinbase for instance).
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        return $token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     * input: MANAUSDT
     * returns: ['MANA', 'USDT']
     */
    public function identifyBaseAndQuote(string $token): array
    {
        $availableQuoteCurrencies = [
            'USDT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB',
            'AUD', 'EUR', 'GBP', 'TRY', 'RUB', 'BRL',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (str_ends_with($token, $quoteCurrency)) {
                return [
                    str_replace($quoteCurrency, '', $token),
                    $quoteCurrency,
                ];
            }
        }

        throw new \InvalidArgumentException("Invalid token format: {$token}");
    }

    /**
     * Resolves response data after a query order api call.
     * RESOLVED
     */
    public function resolveQueryOrderData(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        $price = $result['avgPrice'] != 0 ? $result['avgPrice'] : $result['price'];
        $quantity = $result['executedQty'] != 0 ? $result['executedQty'] : $result['origQty'];

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
