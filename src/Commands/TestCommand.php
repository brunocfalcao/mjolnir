<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\ExchangeSymbol;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Testing stuff';

    public function handle()
    {
        dd(ExchangeSymbol::find(15)->parsedTradingPair('binance'));

        return 0;
    }
}
