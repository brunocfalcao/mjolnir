<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Position;

class ComputeWAPCommand extends Command
{
    protected $signature = 'debug:compute-wap {position_id}';

    protected $description = 'Computes a WAP and returns the WAP information';

    public function handle()
    {
        $positionId = $this->argument('position_id');

        $position = Position::findOrFail($positionId);

        dd($position->calculateWAP());
    }
}
