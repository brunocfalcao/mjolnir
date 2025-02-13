<?php

namespace Nidavellir\Mjolnir\Observers;

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class ExchangeSymbolApiObserver
{
    public function updated(ExchangeSymbol $exchangeSymbol)
    {
        /**
         * Update the price on the position that have this exchange symbol
         */
        if ($exchangeSymbol->wasChanged('last_mark_price')) {
            Position::opened()
                ->where('exchange_symbol_id', $exchangeSymbol->id)
                ->update(['current_price' => $exchangeSymbol->last_mark_price]);
        }
    }
}
