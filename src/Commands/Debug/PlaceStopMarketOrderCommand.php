<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\User;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\PlaceStopMarketOrderJob;

class PlaceStopMarketOrderCommand extends Command
{
    protected $signature = 'debug:place-stop-market-order {position_id}';

    protected $description = 'Places a stop market order for a position id';

    public function handle()
    {
        $positionId = $this->argument('position_id');

        $position = Position::find($positionId);

        $dispatchAt = now()->addMinutes(2);

        CoreJobQueue::create([
            'class' => PlaceStopMarketOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $position->id,
            ],
            'dispatch_after' => $dispatchAt
        ]);

        User::admin()->get()->each(function ($user) use ($dispatchAt, $position) {
            $user->pushover(
                message: "Stop Market order triggered for {$position->parsedTradingPair} to be placed at {$dispatchAt->format('H:i:s')}",
                title: "Stop Market Order Scheduled - {$position->parsedTradingPair}",
                applicationKey: 'nidavellir_warnings'
            );
        });

        return 0;
    }
}
