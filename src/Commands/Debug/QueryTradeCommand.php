<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\UpdatePnLJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class QueryTradeCommand extends Command
{
    protected $signature = 'debug:query-trade {position_id}';

    protected $description = 'Queries an exchange trade given the position id';

    public function handle()
    {
        // Get the argument
        $positionId = $this->argument('position_id');

        $position = Position::findOrFail($positionId);

        CoreJobQueue::create([
            'class' => UpdatePnLJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
            // 'dispatch_after' => now()->addSeconds(10)
        ]);

        // $apiResponse = $position->apiQueryTrade();

        // Dump the order information
        // dd($apiResponse->result[0]['realizedPnl']);

        return 0;
    }
}
