<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeMySQLBinaryLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mjolnir:purge-binary-logs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically purge MySQL binary logs older than 2 days.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Calculate the purge date (2 days ago from now)
        $purgeDate = now()->subDays(2)->format('Y-m-d H:i:s');

        try {
            // Run the PURGE BINARY LOGS command
            DB::unprepared("PURGE BINARY LOGS BEFORE '{$purgeDate}';");

            $this->info("Successfully purged MySQL binary logs older than 2 days (before {$purgeDate}).");
        } catch (\Exception $e) {
            $this->error("An error occurred while purging binary logs: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
