<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class OptimizeCommand extends Command
{
    protected $signature = 'mjolnir:optimize';

    protected $description = 'Clean log files and clear Laravel cache to free disk space';

    public function handle()
    {
        $hostname = gethostname();

        $this->cleanLogs();
        $this->clearCache();
        $this->optimize();
    }

    private function cleanLogs()
    {
        $logPath = storage_path('logs');
        $files = File::allFiles($logPath);

        foreach ($files as $file) {
            File::delete($file);
        }
    }

    private function clearCache()
    {
        Artisan::call('optimize:clear');
        sleep(1);
    }

    private function optimize()
    {
        Artisan::call('optimize');
    }
}
