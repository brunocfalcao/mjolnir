<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Support\Collections\EligibleExchangeSymbolsForPosition;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Testing stuff';

    public function handle()
    {
        // Create a dummy position.
        DB::table('positions')->truncate();

        Position::create([
            'account_id' => 1,
        ]);

        // Obtain active positions.

        $account = Account::find(1);

        $positions = $account->apiQueryPositions()->result;

        $dataMapper = new ApiDataMapperProxy($account->apiSystem->canonical);

        $exchangeSymbols = collect();

        foreach ($positions as $pair => $position) {
            $arrBaseQuote = $dataMapper->identifyBaseAndQuote($pair);

            // Find the right symbol given the base value.
            foreach (Symbol::all() as $symbol) {
                if ($symbol->exchangeCanonical($account->apiSystem) == $arrBaseQuote['base']) {
                    $exchangeSymbols->push(
                        ExchangeSymbol::where('symbol_id', $symbol->id)
                            ->where('quote_id', $account->quote->id)
                            ->first()
                    );
                }
            }
        }

        $exchangeSymbol = EligibleExchangeSymbolsForPosition::getBestExchangeSymbol(Position::find(1), collect());

        dd($exchangeSymbol->symbol->token.'/'.$exchangeSymbol->quote->canonical.' ('.$exchangeSymbol->direction.')');

        return 0;
    }
}
