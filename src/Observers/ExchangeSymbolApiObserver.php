<?php

namespace Nidavellir\Mjolnir\Observers;

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class ExchangeSymbolApiObserver
{
    public function updated(ExchangeSymbol $exchangeSymbol)
    {
        info('ApiObserver for exchange symbol id ' . $exchangeSymbol->id);
        Position::opened()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->update(['last_mark_price' => $exchangeSymbol->last_mark_price]);
    }
}
