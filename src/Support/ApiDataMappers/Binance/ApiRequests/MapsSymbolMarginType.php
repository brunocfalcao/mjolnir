<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Symbol;

trait MapsSymbolMarginType
{
    public function prepareUpdateMarginTypeProperties(Symbol $symbol, Account $account): ApiProperties
    {
        /**
         * Obtain the trading pair for this exchange.
         */
        $symbol = get_base_token_for_exchange($symbol->token, $account->apiSystem->canonical);
        $parsedSymbol = $this->baseWithQuote($symbol, $account->quote->canonical);

        $properties = new ApiProperties;
        $properties->set('options.symbol', $parsedSymbol);
        $properties->set('options.margintype', 'CROSSED');

        return $properties;
    }

    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
