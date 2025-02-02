<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\User;

class PurgeDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mjolnir:purge-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purges MySQL binary logs and cleans up data from tables that grow significantly.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Calculate the purge date (2 days ago from now)
        $purgeDate2DaysAgo = now()->subDays(2)->format('Y-m-d H:i:s');
        $purgeDate10DaysAgo = now()->subDays(10)->format('Y-m-d H:i:s');
        $purgeDate1MonthAgo = now()->subMonth()->format('Y-m-d H:i:s');

        $binaryLogStatus = '';
        $deletedJobQueueEntries = 0;
        $deletedApiRequestLogs = 0;
        $deletedPriceHistoryLogs = 0;

        try {
            // Purge MySQL binary logs
            DB::unprepared("PURGE BINARY LOGS BEFORE '{$purgeDate2DaysAgo}';");

            // Purge data from core_job_queue table
            $deletedJobQueueEntries = DB::table('core_job_queue')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            // Purge data from api_requests_log table
            $deletedApiRequestLogs = DB::table('api_requests_log')
                ->where('created_at', '<', $purgeDate2DaysAgo)
                ->delete();

            // Purge data from order_history table
            $deletedOrderHistoryLogs = DB::table('order_history')
                ->where('created_at', '<', $purgeDate10DaysAgo)
                ->delete();

            // Purge data from the price_history table
            $deletedPriceHistoryLogs = DB::table('order_history')
                ->where('created_at', '<', $purgeDate1MonthAgo)
                ->delete();

            // Send Pushover notification with report
            // $this->sendReport($purgeDate2DaysAgo, $purgeDate10DaysAgo, $purgeDate1MonthAgo, $deletedJobQueueEntries, $deletedApiRequestLogs, $deletedOrderHistoryLogs, $deletedPriceHistoryLogs);
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Sends a Pushover notification to admin users with the purge summary.
     *
     * @param  string  $purgeDate
     * @param  int  $deletedJobQueueEntries
     * @param  int  $deletedApiRequestLogs
     * @return void
     */
    protected function sendReport($purgeDate2DaysAgo, $purgeDate10DaysAgo, $purgeDate1MonthAgo, $deletedJobQueueEntries, $deletedApiRequestLogs, $deletedOrderHistoryLogs, $deletedPriceHistoryLogs)
    {
        User::admin()->get()->each(function ($user) use ($deletedJobQueueEntries, $deletedApiRequestLogs, $deletedOrderHistoryLogs, $deletedPriceHistoryLogs) {
            $message = "Purge Summary - core_job_queue: {$deletedJobQueueEntries} entries, api_requests_log: {$deletedApiRequestLogs} entries, order_history: {$deletedOrderHistoryLogs}, price_history: {$deletedPriceHistoryLogs}";

            $user->pushover(
                message: $message,
                title: 'Data Purge Completed',
                applicationKey: 'nidavellir_cronjobs'
            );
        });
    }
}
