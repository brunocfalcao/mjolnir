<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\CoreJobQueue;

class DispatchCommand extends Command
{
    protected $signature = 'excalibur:core-job-queue-dispatch';

    protected $description = 'Dispatch all pending API jobs';

    public function handle()
    {
        CoreJobQueue::dispatch();
        $this->info(now());

        return 0;
    }
}
