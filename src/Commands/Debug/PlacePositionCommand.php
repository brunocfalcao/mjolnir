<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Jobs\Processes\CreatePosition\CreatePositionLifecycleJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;

class PlacePositionCommand extends Command
{
    protected $signature = 'debug:place-position
                            {--token= : The token for the position (mandatory)}
                            {--margin= : The margin amount (optional)}
                            {--leverage= : The leverage level (optional)}
                            {--direction= : The trade direction (long/short) (optional)}
                            {--profitPercentage= : The target profit percentage (optional)}
                            {--clean : Truncate relevant tables before execution (optional)}';

    protected $description = 'Places a position (creates position, orders, adds to the exchange, overrides max positions since this is debug)';

    public function handle()
    {
        file_put_contents(storage_path('logs/laravel.log'), '');

        if ($this->option('clean')) {
            $this->cleanDatabase();
        }

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

        if ($direction == null && ! $exchangeSymbol->isTradeable()) {
            $this->error("Exchange Symbol for {$token} doesn't have a tradeable direction defined! Aborting ...");

            return 1;
        }

        $account = Account::find(1);

        $openPositions = Position::active()
            ->where('account_id', $account->id)
            ->pluck('exchange_symbol_id');

        if ($openPositions->contains($exchangeSymbol->id)) {
            $this->error("A position for Exchange Symbol ID {$exchangeSymbol->id} already exists. Aborting ...");

            return 1;
        }

        $this->info('Proceeding with position creation...');

        if ($direction == null) {
            $direction = $exchangeSymbol->direction;
        }

        $position = Position::create([
            'account_id' => $account->id,
            'exchange_symbol_id' => $exchangeSymbol->id,
            'margin' => $margin,
            'leverage' => $leverage,
            'direction' => $direction,
            'profit_percentage' => $profitPercentage,
        ]);

        CoreJobQueue::create([
            'class' => CreatePositionLifecycleJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
        ]);

        return 0;
    }

    private function cleanDatabase()
    {
        $this->info('Cleaning database tables...');
        DB::statement('TRUNCATE TABLE core_job_queue');
        DB::statement('TRUNCATE TABLE positions');
        DB::statement('TRUNCATE TABLE orders');
        DB::statement('TRUNCATE TABLE api_requests_log');
        $this->info('Database cleanup complete.');
    }
}
