<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeDataCommand extends Command
{
    protected $signature = 'mjolnir:purge-data';

    protected $description = 'Purges MySQL binary logs and cleans up data from tables that grow significantly.';

    public function handle()
    {
        // Calculate the purge date for 2 days ago.
        $purgeDate2DaysAgo = now()->subDays(2)->format('Y-m-d H:i:s');
        // Calculate the purge date for 10 days ago.
        $purgeDate10DaysAgo = now()->subDays(10)->format('Y-m-d H:i:s');
        // Calculate the purge date for 1 month ago.
        $purgeDate1MonthAgo = now()->subMonth()->format('Y-m-d H:i:s');

        // Initialize variables for reporting purposes.
        $binaryLogStatus = '';
        $deletedJobQueueEntries = 0;
        $deletedApiRequestLogs = 0;
        $deletedOrderHistoryLogs = 0;
        $deletedPriceHistoryLogs = 0;

        try {
            // Purge MySQL binary logs before the calculated date.
            DB::unprepared("PURGE BINARY LOGS BEFORE '{$purgeDate2DaysAgo}';");

            // Delete entries older than 2 days from the core_job_queue table.
            $deletedJobQueueEntries = DB::table('core_job_queue')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            // Delete entries older than 2 days from the api_requests_log table.
            $deletedApiRequestLogs = DB::table('api_requests_log')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            // Delete entries older than 10 days from the order_history table.
            $deletedOrderHistoryLogs = DB::table('order_history')
                ->where('created_at', '<', $purgeDate10DaysAgo)
                ->delete();

            // Delete entries older than 1 month from the price_history table.
            $deletedPriceHistoryLogs = DB::table('price_history')
                ->where('created_at', '<', $purgeDate1MonthAgo)
                ->delete();

            // The report notification functionality has been removed.
        } catch (\Exception $e) {
            // Log the error message.
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
