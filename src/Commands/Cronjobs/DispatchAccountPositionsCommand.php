<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\Positions\DispatchNewAccountPositionJob;
use Nidavellir\Mjolnir\Jobs\Processes\Positions\VerifyBalanceConditionsJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;

class DispatchAccountPositionsCommand extends Command
{
    protected $signature = 'excalibur:dispatch-account-positions';

    protected $description = 'Dispatch all possible remaining to be opened positions, for all possible accounts';

    public function handle()
    {
        // Clear logs and truncate tables
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('core_job_queue')->truncate();
        DB::table('api_requests_log')->truncate();
        DB::table('rate_limits')->truncate();
        DB::table('positions')->truncate();
        DB::table('orders')->truncate();

        $this->createTestingData();

        // The BTC exchange symbol shouldn't be tradeable. Enforce it.
        ExchangeSymbol::where('symbol_id', Symbol::firstWhere('token', 'BTC')->id)
            ->update(['is_tradeable' => false]);

        // Only process accounts belonging to traders.
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true); // Ensure the user is a trader
        })->active()->get();

        foreach ($accounts as $account) {
            // Get open positions for the account.
            $openPositions = Position::opened()->where('account_id', $account->id)->get();

            // Calculate the delta.
            $delta = $account->max_concurrent_trades - $openPositions->count();

            // Dispatch jobs for each delta.
            if ($delta > 0) {
                for ($i = 0; $i < $delta; $i++) {
                    $blockUuid = (string) Str::uuid();

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
                     * This will create a new position in the database, only
                     * with the account and with the syncing on.
                     */
                    CoreJobQueue::create([
                        'class' => DispatchNewAccountPositionJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'accountId' => $account->id,
                        ],
                        'index' => 2,
                        'block_uuid' => $blockUuid,
                    ]);
                }
            }
        }

        return 0;
    }

    protected function createTestingData()
    {
        // Create the first Position (fast-traded, satisfies the criteria)
        $fastTradedPosition = Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => 8, // Replace with a valid exchange_symbol_id
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'new', // Set the status to closed
        ]);

        // Create the first Position (fast-traded, satisfies the criteria)
        $fastTradedPosition = Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => 11, // Replace with a valid exchange_symbol_id
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'closed', // Set the status to closed
        ]);

        // Create the second Position (does not satisfy the criteria, created more than 5 minutes ago)
        $oldPosition = Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => 3, // Replace with a valid exchange_symbol_id
            'started_at' => Carbon::now()->subMinutes(10), // Started 10 minutes ago
            'closed_at' => Carbon::now()->subMinutes(7), // Closed 7 minutes ago
            'created_at' => Carbon::now()->subMinutes(10), // Created 10 minutes ago
            'updated_at' => Carbon::now()->subMinutes(7), // Updated 7 minutes ago
            'status' => 'closed', // Set the status to closed
        ]);

        // Create the first Position (fast-traded, satisfies the criteria)
        $fastTradedPosition = Position::create([
            'account_id' => 1, // Replace with a valid account_id
            'exchange_symbol_id' => 12, // Replace with a valid exchange_symbol_id
            'started_at' => Carbon::now()->subMinutes(2), // Started 2 minutes ago
            'closed_at' => Carbon::now(), // Closed now
            'created_at' => Carbon::now()->subMinutes(2), // Created 2 minutes ago
            'updated_at' => Carbon::now(), // Updated now
            'status' => 'closed', // Set the status to closed
        ]);
    }
}
