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
        $purgeDate = now()->subDays(2)->format('Y-m-d H:i:s');

        $binaryLogStatus = '';
        $deletedJobQueueEntries = 0;
        $deletedApiRequestLogs = 0;

        try {
            // Purge MySQL binary logs
            DB::unprepared("PURGE BINARY LOGS BEFORE '{$purgeDate}';");
            $binaryLogStatus = "Successfully purged MySQL binary logs older than 2 days (before {$purgeDate}).";
            $this->info($binaryLogStatus);

            // Purge data from core_job_queue table
            $deletedJobQueueEntries = DB::table('core_job_queue')
                ->where('created_at', '<', $purgeDate)
                ->delete();
            $this->info("Deleted {$deletedJobQueueEntries} entries from core_job_queue older than 2 days.");

            // Purge data from api_requests_log table
            $deletedApiRequestLogs = DB::table('api_requests_log')
                ->where('created_at', '<', $purgeDate)
                ->delete();
            $this->info("Deleted {$deletedApiRequestLogs} entries from api_requests_log older than 2 days.");

            // Send Pushover notification with report
            $this->sendReport($purgeDate, $deletedJobQueueEntries, $deletedApiRequestLogs);
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Sends a Pushover notification to admin users with the purge summary.
     *
     * @param string $purgeDate
     * @param int $deletedJobQueueEntries
     * @param int $deletedApiRequestLogs
     * @return void
     */
    protected function sendReport($purgeDate, $deletedJobQueueEntries, $deletedApiRequestLogs)
    {
        User::admin()->get()->each(function ($user) use ($purgeDate, $deletedJobQueueEntries, $deletedApiRequestLogs) {
            $message = "Purge Summary:\n"
                     . "Purge Date: {$purgeDate}\n"
                     . "Deleted from core_job_queue: {$deletedJobQueueEntries} entries\n"
                     . "Deleted from api_requests_log: {$deletedApiRequestLogs} entries";

            $user->pushover(
                message: $message,
                title: 'Data Purge Completed',
                applicationKey: 'nidavellir_cronjobs'
            );
        });
    }
}
