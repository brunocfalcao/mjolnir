<?php

namespace Nidavellir\Mjolnir\Observers;

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class ExchangeSymbolApiObserver
{
    public function updated(ExchangeSymbol $exchangeSymbol)
    {
        return;

        Position::opened()
        ->where('exchange_symbol_id', $exchangeSymbol->id)
        ->get()
        ->each(function ($position) use ($exchangeSymbol) {
            $position->last_mark_price = $exchangeSymbol->last_mark_price;
            $position->save();
        });
    }
}
