<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
use Nidavellir\Thor\Models\CoreJobQueue;

class ClosePositionCommand extends Command
{
    protected $signature = 'debug:close-position {position_id}';

    protected $description = 'Cancel all open orders for a specific position id';

    public function handle()
    {
        $positionId = $this->argument('position_id');

        CoreJobQueue::create([
            'class' => ClosePositionLifecycleJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $positionId,
            ],
        ]);

        return 0;
    }
}
