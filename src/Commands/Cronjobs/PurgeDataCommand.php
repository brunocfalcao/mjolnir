<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\User;

class PurgeDataCommand extends Command
{
    protected $signature = 'mjolnir:purge-data';

    protected $description = 'Purges MySQL binary logs and cleans up data from tables that grow significantly.';

    public function handle()
    {
        $purgeDate2DaysAgo = now()->subDays(2)->format('Y-m-d H:i:s');
        $purgeDate10DaysAgo = now()->subDays(10)->format('Y-m-d H:i:s');
        $purgeDate1MonthAgo = now()->subMonth()->format('Y-m-d H:i:s');

        $deletedJobQueueEntries = 0;
        $deletedApiRequestLogs = 0;
        $deletedOrderHistoryLogs = 0;
        $deletedPriceHistoryLogs = 0;

        try {
            DB::unprepared("PURGE BINARY LOGS BEFORE '{$purgeDate2DaysAgo}';");

            $deletedJobQueueEntries = DB::table('core_job_queue')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            $deletedApiRequestLogs = DB::table('api_requests_log')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            $deletedOrderHistoryLogs = DB::table('order_history')
                ->where('created_at', '<', $purgeDate10DaysAgo)
                ->delete();

            $deletedPriceHistoryLogs = DB::table('price_history')
                ->where('created_at', '<', $purgeDate1MonthAgo)
                ->delete();

            // Notify admins with cleanup summary
            $summary = "Purge Summary:\n"
            ."• JobQueue: {$deletedJobQueueEntries} rows\n"
            ."• API Logs: {$deletedApiRequestLogs} rows\n"
            ."• Order History: {$deletedOrderHistoryLogs} rows\n"
            ."• Price History: {$deletedPriceHistoryLogs} rows";

            User::admin()->get()->each(function ($user) use ($summary) {
                $user->pushover(
                    message: $summary,
                    title: 'Mjolnir Purge Summary',
                    applicationKey: 'nidavellir_warnings'
                );
            });
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
