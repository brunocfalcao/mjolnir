<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance;

use Nidavellir\Mjolnir\Abstracts\BaseDataMapper;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsOrderQuery;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsPlaceOrder;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsMarkPriceQuery;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsSymbolMarginType;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsAccountBalanceQuery;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsSymbolLeverageRatios;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsLeverageBracketsQuery;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests\MapsExchangeInformationQuery;

class BinanceApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsExchangeInformationQuery;
    use MapsLeverageBracketsQuery;
    use MapsMarkPriceQuery;
    use MapsOrderQuery;
    use MapsSymbolLeverageRatios;
    use MapsSymbolMarginType;
    use MapsPlaceOrder;

    /**
     * The BUY canonical for the limit orders. For instance the LIMIT-BUY order
     * type canonical.
     */
    public function buyType()
    {
        return 'BUY';
    }

    /**
     * The SELL canonical for the limit orders. For instance the LIMIT-SELL order
     * type canonical.
     */
    public function sellType()
    {
        return 'SELL';
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
                    'base' => str_replace($quoteCurrency, '', $token),
                    'quote' => $quoteCurrency,
                ];
            }
        }

        throw new \InvalidArgumentException("Invalid token format: {$token}");
    }
}
