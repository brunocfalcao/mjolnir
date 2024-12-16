<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\Positions\DispatchNewAccountPositionJob;
use Nidavellir\Mjolnir\Jobs\Processes\Positions\VerifyBalanceConditionsJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

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

        // Only process accounts belonging to traders
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true); // Ensure the user is a trader
        })->active()->get();

        foreach ($accounts as $account) {
            // Get open positions for the account
            $openPositions = Position::opened()->where('account_id', $account->id)->get();

            // Calculate the delta
            $delta = $account->max_concurrent_trades - $openPositions->count();

            // Dispatch jobs for each delta
            if ($delta > 0) {
                $blockUuid = (string) Str::uuid();

                CoreJobQueue::create([
                    'class' => VerifyBalanceConditionsJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'accountId' => $account->id,
                    ],
                    'index' => 1,
                    'block_uuid' => $blockUuid,
                ]);

                for ($i = 0; $i < $delta; $i++) {
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
}
