<?php

namespace Nidavellir\Mjolnir\Concerns\Models\ExchangeSymbol;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

trait HasTokenParsingFeatures
{
    // Accessor to return a full exchange-ready trading position trading pair.
    public function parsedTradingPair(string $apiSystemCanonical)
    {
        $this->load(['symbol', 'quote']);

        $dataMapper = new ApiDataMapperProxy($apiSystemCanonical);

        $symbol = get_base_token_for_exchange($this->symbol->token, $apiSystemCanonical);

        $parsedSymbol = $dataMapper->baseWithQuote($symbol, $this->quote->canonical);

        return $parsedSymbol;
    }
}

/*
*/
