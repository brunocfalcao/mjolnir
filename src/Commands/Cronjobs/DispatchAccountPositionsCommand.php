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
                $blockUuid = (string) Str::uuid();

                for ($i = 0; $i < $delta; $i++) {
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

                    /**
                     * Next is to select the right token. A token selection have
                     * tons of logic and rules to select the best token at the
                     * right time, for the right profit, with the right
                     * direction.
                     */
                    CoreJobQueue::create([
                        'class' => SelectPositionTokenJob::class,
                        'queue' => 'positions',
                        'index' => 3,
                        'block_uuid' => $blockUuid,
                    ]);

                    /**
                     * Next is to calculate the position amount, also given
                     * risk-management conditions and the respective
                     * margin that will be used (without leverage).
                     */

                    /*
                    CoreJobQueue::create([
                        'class' => CalculatePositionAmountJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 4,
                        'block_uuid' => $blockUuid,
                    ]);

                    CoreJobQueue::create([
                        'class' => CalculatePositionLeverageJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 5,
                        'block_uuid' => $blockUuid,
                    ]);

                    CoreJobQueue::create([
                        'class' => UpdateMarginTypeToCrossedJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 6,
                        'block_uuid' => $blockUuid,
                    ]);

                    CoreJobQueue::create([
                        'class' => UpdateTokenLeverageRatioJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 7,
                        'block_uuid' => $blockUuid,
                    ]);

                    CoreJobQueue::create([
                        'class' => UpdateRemainingPositionDataJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 8,
                        'block_uuid' => $blockUuid,
                    ]);

                    CoreJobQueue::create([
                        'class' => DispatchPositionOrdersJob::class,
                        'queue' => 'orders',
                        'arguments' => [
                            'positionId' => $this->position->id,
                        ],
                        'index' => 9,
                        'block_uuid' => $blockUuid,
                    ]);
                    */
                }
            }
        }

        return 0;
    }
}
