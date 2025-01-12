<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\CoreJobQueue;

class DispatchCoreJobQueueCommand extends Command
{
    protected $signature = 'mjolnir:dispatch-core-job-queue';

    protected $description = 'Dispatch all pending API jobs';

    public function handle()
    {
        CoreJobQueue::dispatch();

        return 0;
    }
}
