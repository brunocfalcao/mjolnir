<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;

trait HasTokenParsingFeatures
{
    // Accessor to return a full exchange-ready trading position trading pair.
    public function getParsedTradingPairAttribute()
    {
        $this->load('exchangeSymbol.symbol');
        $this->load('account.apiSystem');

        $symbol = get_base_token_for_exchange($this->exchangeSymbol->symbol->token, $this->account->apiSystem->canonical);
        $parsedSymbol = $this->baseWithQuote($symbol, $this->account->quote->canonical);

        return $parsedSymbol;
    }
}

/*
*/
