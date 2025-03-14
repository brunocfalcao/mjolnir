<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Symbol;

class PlacePositionCommand extends Command
{
    protected $signature = 'debug:place-position
                            {--token= : The token for the position (mandatory)}
                            {--margin= : The margin amount (optional)}
                            {--leverage= : The leverage level (optional)}
                            {--direction= : The trade direction (long/short) (optional)}
                            {--profitPercentage= : The target profit percentage (optional)}';

    protected $description = 'Places a position (creates position, orders, adds to the exchange, overrides max positions since this is debug)';

    public function handle()
    {
        $token = $this->option('token');

        if (! $token) {
            $this->error('The --token option is required.');

            return 1;
        }

        $margin = $this->option('margin');
        $leverage = $this->option('leverage');
        $direction = $this->option('direction');
        $profitPercentage = $this->option('profitPercentage');

        $this->info('Placing position with:');
        $this->info("Token: $token");
        if ($margin) {
            $this->info("Margin: $margin");
        }
        if ($leverage) {
            $this->info("Leverage: $leverage");
        }
        if ($direction) {
            $this->info("Direction: $direction");
        }
        if ($profitPercentage) {
            $this->info("Profit Percentage: $profitPercentage");
        }

        $symbol = Symbol::firstWhere('token', $token);

        if (! $symbol) {
            $this->error("Symbol {$token} not found! Aborting ...");

            return 1;
        }

        $exchangeSymbol = ExchangeSymbol::where('quote_id', 1)
            ->where('symbol_id', $symbol->id)
            ->first();

        if (! $exchangeSymbol) {
            $this->error("Exchange Symbol for {$token} not found! Aborting ...");

            return 1;
        }

        if (! $direction == null && $exchangeSymbol->isTradeable) {
            $this->error("Exchange Symbol for {$token} doesnt have a direction defined! Aborting ...");

            return 1;
        }

        /*
        $position = Position::create([
        ]);
        */

        return 0;
    }
}
