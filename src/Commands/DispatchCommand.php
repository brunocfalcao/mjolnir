<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\ApiJob;

class DispatchCommand extends Command
{
    protected $signature = 'excalibur:dispatch';

    protected $description = 'Dispatch all pending API jobs';

    public function handle()
    {
        ApiJob::dispatch();

        $this->info('All pending API jobs have been dispatched.');

        return 0;
    }
}
