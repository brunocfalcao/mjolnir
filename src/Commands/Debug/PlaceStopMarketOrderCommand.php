<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\PlaceStopMarketOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;

class PlaceStopMarketOrderCommand extends Command
{
    protected $signature = 'debug:place-stop-market-order {position_id}';

    protected $description = 'Places a stop market order for a position id';

    public function handle()
    {
        $positionId = $this->argument('position_id');

        CoreJobQueue::create([
            'class' => PlaceStopMarketOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $positionId,
            ],
        ]);

        return 0;
    }
}
