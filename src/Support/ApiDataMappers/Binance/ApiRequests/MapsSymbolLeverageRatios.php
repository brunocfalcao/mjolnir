<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Symbol;

trait MapsSymbolLeverageRatios
{
    public function prepareUpdateLeverageRatioProperties(Symbol $symbol, Account $account, int $leverage): ApiProperties
    {
        /**
         * Obtain the trading pair for this exchange.
         */
        $symbol = get_trading_pair_for_exchange($symbol->token, $account->apiSystem->canonical);
        $parsedSymbol = $this->baseWithQuote($symbol, $account->quote->canonical);

        $properties = new ApiProperties;
        $properties->set('options.symbol', $parsedSymbol);
        $properties->set('options.leverage', $leverage);

        return $properties;
    }

    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
