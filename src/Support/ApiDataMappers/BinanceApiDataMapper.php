<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Order;

class BinanceApiDataMapper
{
    /**
     * Used for the resolve() on the UpsertLeverageAndNotionalBracketsJob.
     * Converts the Binance result to the array like:
     * [
     *     [
     *         'symbol' => 'KASUSDT',
     *         'brackets' => [
     *             [
     *                 'leverage' => 75,
     *                 'max' => 5000,
     *                 'min' => 0,
     *             ],
     *             // Other brackets...
     *         ],
     *     ],
     *     // More symbols...
     * ]
     */
    public function resolveLeverageAndNotionalBracketsProperties(array $exchangeSymbols): array
    {
        return array_map(function ($exchangeSymbol) {
            $brackets = array_map(function ($bracket) {
                return [
                    'leverage' => $bracket['initialLeverage'],
                    'max' => $bracket['notionalCap'],
                    'min' => $bracket['notionalFloor'],
                ];
            }, $exchangeSymbol['brackets']);

            return [
                'symbol' => $exchangeSymbol['symbol'],
                'brackets' => $brackets,
            ];
        }, $exchangeSymbols);
    }

    /**
     * Used for preparing the properties for the call for the
     * get notional leverage brackets.
     */
    public function prepareLeverageAndNotionalBracketsProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    /**
     * Prepares data for the Job UpsertExchangeInformationJob.
     * The ApiProperties will be based on each exchange
     * parameters information.
     */
    public function prepareExchangeInformationProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    /**
     * The array is the one from the UpdateExchangeInformation
     * and we need to return an array like:
     * [
     *     'pricePrecision' => 2,
     *     'quantityPrecision' => 3,
     *     'tickSize' => 0.01,
     *     'minNotional' => 100,
     * ]
     */
    public function resolveExchangeInformationResponse(array $data): array
    {
        return collect($data['symbols'] ?? [])->map(function ($symbolData) {
            $filters = collect($symbolData['filters'] ?? []);

            return [
                'symbol' => $symbolData['symbol'],
                'pricePrecision' => $symbolData['pricePrecision'],
                'quantityPrecision' => $symbolData['quantityPrecision'],
                'tickSize' => (float) $filters->firstWhere('filterType', 'PRICE_FILTER')['tickSize'] ?? null,
                'minNotional' => (float) $filters->firstWhere('filterType', 'MIN_NOTIONAL')['notional'] ?? null,
            ];
        })->toArray();
    }

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
     * Returns an array with the different available account balances
     * per quote currency. The delta balance means we will try to
     * subtract the unrealized PnL from the total balance so we
     * will trade only with what's remaining at that moment.
     *
     * Example:
     * ['USDT' => 765.33, 'USDC' => 123.11]
     */
    public function getAccountDeltaBalances(Response $response): array
    {
        return collect(json_decode($response->getBody(), true))
            ->mapWithKeys(function ($item) {
                return [$item['asset'] => (float) $item['balance'] + (float) $item['crossUnPnl']];
            })
            ->toArray();
    }

    public function prepareMarginTypeUpdateProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.margintype', 'CROSSED');

        return $properties;
    }

    public function prepareTokenLeverageUpdateProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.leverage', $data['leverage']);

        return $properties;
    }

    public function prepareTokenGetMarkPrice(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);

        return $properties;
    }

    /**
     * array:8 [
     *      "symbol" => "NEARUSDT"
     *      "markPrice" => "4.25100000"
     *      "indexPrice" => "4.25379511"
     *      "estimatedSettlePrice" => "4.24135185"
     *      "lastFundingRate" => "-0.00000650"
     *      "interestRate" => "0.00010000"
     *      "nextFundingTime" => 1730160000000
     *      "time" => 1730150128000
     * ]
     */
    public function resolveTokenGetMarkPrice(Response $response): float
    {
        return (float) json_decode($response->getBody(), true)['markPrice'];
    }

    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $symbol = $order->position->exchangeSymbol->symbol;

        $properties->set('options.symbol', $this->baseWithQuote($symbol->token, $order->position->exchangeSymbol->quote->canonical));
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

    public function resolvePlaceOrderData(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        return [
            'order_id' => $result['orderId'],
            'average_price' => $result['avgPrice'],
            'price' => $result['price'],
            'original_quantity' => $result['origQty'],
            'executed_quantity' => $result['executedQty'],
        ];
    }

    public function prepareIncomeProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.incomeType', 'REALIZED_PNL');

        return $properties;
    }

    public function prepareTradeProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.orderId', $data['order_id']);

        return $properties;
    }

    public function resolveTradeResponse(Response $response): float
    {
        $result = json_decode($response->getBody(), true);

        if (array_key_exists(0, $result)) {
            return $result[0]['realizedPnl'];
        }

        return 0;
    }

    /**
     * Prepares data to call the query order.
     */
    public function prepareQueryOrderProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.orderId', $data['order_id']);

        return $properties;
    }

    /**
     * Resolves response data after a query order api call.
     */
    public function resolveQueryOrderData(Response $response): array
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

    /**
     * Prepares data to cancel all open orders.
     */
    public function prepareCancelAllOpenOrdersProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);

        return $properties;
    }

    /**
     * Prepares data to get all positions or only one position.
     */
    public function prepareGetPositionsProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        if (isset($data['symbol'])) {
            $properties->set('options.symbol', $data['symbol']);
        }

        return $properties;
    }

    /**
     * Resolves response data for positions.
     */
    public function resolveGetPositionsResponse(Response $response): array
    {
        $data = collect(json_decode($response->getBody(), true))
            ->map(function ($item) {
                return [
                    'symbol' => $item['symbol'],
                    'current_quantity' => $item['positionAmt'],
                    'initial_average_price' => $item['entryPrice'],
                    'current_average_price' => $item['markPrice'],
                    'unrealized_profit' => $item['unRealizedProfit'],
                    'size' => $item['notional'],
                ];
            });

        return $data->isNotEmpty() ? $data->first() : [];
    }

    public function parseTokenPrice(Collection $prices, ExchangeSymbol $exchangeSymbol)
    {
        /**
         * array:8 [
  "e" => "markPriceUpdate"
  "E" => 1730662691000
  "s" => "BTCUSDT"
  "p" => "68597.50000000"
  "P" => "68181.59255266"
  "i" => "68598.58212766"
  "r" => "0.00010000"
  "T" => 1730678400000
]
         */

        return $prices->where('s', $this->baseWithQuote(
            $exchangeSymbol->symbol->token,
            $exchangeSymbol->quote->canonical
        ))->first()['p'];
    }

    public function prepareModifyOrderProperties(array $data): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', $data['symbol']);
        $properties->set('options.orderId', $data['order_id']);
        $properties->set('options.side', $data['side']);
        $properties->set('options.quantity', $data['quantity']);
        $properties->set('options.price', $data['price']);

        return $properties;
    }

    public function resolveIncomeData(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        return $result;
    }

    public function resolveModifyOrderData(Response $response): array
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
