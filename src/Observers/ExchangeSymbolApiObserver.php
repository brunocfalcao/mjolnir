<?php

namespace Nidavellir\Mjolnir\Observers;

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class ExchangeSymbolApiObserver
{
    public function updated(ExchangeSymbol $exchangeSymbol)
    {
        if ($exchangeSymbol->wasChanged('last_mark_price')) {
            info('Updating positions at ' . now());

            Position::opened()
                ->where('exchange_symbol_id', $exchangeSymbol->id)
                ->update(['last_mark_price' => $exchangeSymbol->last_mark_price]);
        }
    }
}
