<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

trait HasTokenParsingFeatures
{
    // Accessor to return a full exchange-ready trading position trading pair.
    public function getParsedTradingPairAttribute()
    {
        $this->load('exchangeSymbol.symbol');
        $this->load('account.apiSystem');

        $dataMapper = new ApiDataMapperProxy($this->account->apiSystem->canonical);

        $symbol = get_base_token_for_exchange($this->exchangeSymbol->symbol->token, $this->account->apiSystem->canonical);
        $parsedSymbol = $dataMapper->baseWithQuote($symbol, $this->account->quote->canonical);

        return $parsedSymbol;
    }
}

/*
*/
