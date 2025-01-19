<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\CreatePosition\CreateNewPositionsJob;
use Nidavellir\Mjolnir\Jobs\Processes\CreatePosition\VerifyBalanceConditionsJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;

class DispatchPositionsCommand extends Command
{
    protected $signature = 'mjolnir:dispatch-positions {--clean : Truncate tables, clear logs, and create testing data before execution}';

    protected $description = 'Dispatch all possible remaining to be opened positions, for all possible accounts';

    public function handle()
    {
        if ($this->option('clean')) {
            File::put(storage_path('logs/laravel.log'), '');
            $this->cleanData();
            // $this->createTestingData();
        }

        // Do we have exchange symbols?
        if (! ExchangeSymbol::query()->exists()) {
            return;
        }

        // The BTC exchange symbol shouldn't be tradeable. Enforce it.
        $btc = ExchangeSymbol::firstWhere('symbol_id', Symbol::firstWhere('token', 'BTC')->id);

        if ($btc) {
            $btc->update(['is_tradeable' => false]);
        }

        // Only process accounts belonging to traders.
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true); // Ensure the user is a trader
        })->with('user')
            ->active()
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            // Get open positions for the account.
            $openPositions = Position::opened()->where('account_id', $account->id)->get();

            // Calculate the delta.
            $delta = $account->max_concurrent_trades - $openPositions->count();

            info('[DispatchAccountPositionsCommand] - Dispatching '.$delta.' position(s) to '.$account->user->name);

            $blockUuid = (string) Str::uuid();

            if ($delta > 0) {
                /**
                 * Verify all the financial conditions to open a new position
                 * for the respective account, and also specific
                 * risk-management conditions that might stop
                 * the position to be opened.
                 */
                CoreJobQueue::create([
                    'class' => VerifyBalanceConditionsJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'accountId' => $account->id,
                    ],
                    'index' => 1,
                    'block_uuid' => $blockUuid,
                ]);

                /**
                 * Create as much positions as the delta using this job.
                 */
                CoreJobQueue::create([
                    'class' => CreateNewPositionsJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'accountId' => $account->id,
                        'numPositions' => $delta,
                    ],
                    'index' => 2,
                    'block_uuid' => $blockUuid,
                ]);
            }
        }

        return 0;
    }

    protected function cleanData()
    {
        // Clear logs and truncate tables
        DB::table('core_job_queue')->truncate();
        DB::table('api_requests_log')->truncate();
        DB::table('rate_limits')->truncate();
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();
    }

    protected function createTestingData()
    {
        // Create the first Position (fast-traded, satisfies the criteria)
        $id = 17;
        Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => $id, // Replace with a valid exchange_symbol_id
            'margin' => Account::find(1)->margin_override,
            'direction' => ExchangeSymbol::find($id)->direction,
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'new', // Set the status to closed
        ]);

        // Create the first Position (fast-traded, satisfies the criteria)
        $id = 15;
        Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => $id, // Replace with a valid exchange_symbol_id
            'margin' => Account::find(1)->margin_override,
            'direction' => ExchangeSymbol::find($id)->direction,
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'closed', // Set the status to closed
        ]);

        // Create the second Position (does not satisfy the criteria, created more than 5 minutes ago)
        $id = 7;
        Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => $id, // Replace with a valid exchange_symbol_id
            'margin' => Account::find(1)->margin_override,
            'direction' => ExchangeSymbol::find($id)->direction,
            'started_at' => Carbon::now()->subMinutes(10), // Started 10 minutes ago
            'closed_at' => Carbon::now()->subMinutes(7), // Closed 7 minutes ago
            'created_at' => Carbon::now()->subMinutes(10), // Created 10 minutes ago
            'updated_at' => Carbon::now()->subMinutes(7), // Updated 7 minutes ago
            'status' => 'new', // Set the status to closed
        ]);

        // Create another Position (fast-traded, satisfies the criteria)
        $id = 14;
        Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => $id, // Replace with a valid exchange_symbol_id
            'margin' => Account::find(1)->margin_override,
            'direction' => ExchangeSymbol::find($id)->direction,
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'closed', // Set the status to closed
        ]);
    }
}
