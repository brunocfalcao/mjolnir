<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class OptimizeCommand extends Command
{
    protected $signature = 'mjolnir:optimize';

    protected $description = 'Clean log files and clear Laravel cache to free disk space.';

    public function handle()
    {
        // Clean log files from the storage directory.
        $this->cleanLogs();

        // Clear Laravel caches using artisan commands.
        $this->clearCache();

        // Run Laravel optimization command.
        $this->optimizeApplication();

        return Command::SUCCESS;
    }

    // Clean all files from the logs directory.
    private function cleanLogs()
    {
        $logPath = storage_path('logs');
        if (File::exists($logPath)) {
            File::cleanDirectory($logPath);
        }
    }

    // Clear Laravel caches using the optimize:clear artisan command.
    private function clearCache()
    {
        Artisan::call('optimize:clear');
        sleep(1);
    }

    // Run Laravel optimization using the optimize artisan command.
    private function optimizeApplication()
    {
        Artisan::call('optimize');
    }
}
