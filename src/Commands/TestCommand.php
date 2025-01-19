<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Support\Collections\EligibleExchangeSymbolsForPosition;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Resolves the Binance recvWindow issue by syncing server time with a 25% safety margin';

    public function handle()
    {
        dd(EligibleExchangeSymbolsForPosition::getBestExchangeSymbol(Position::find(1))->symbol->token);

        return;

        $apiResponse = ApiSystem::find(1)->apiQueryMarketData();

        dd($apiResponse->response);

        return 0;
    }
}
