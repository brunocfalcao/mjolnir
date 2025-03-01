<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\SyncOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class SyncOrdersCommand extends Command
{
    protected $signature = 'mjolnir:sync-orders';

    protected $description = 'Syncs all orders, and accordingly to the changes, triggers WAP, Close positions, etc';

    public function handle()
    {
        // Fetch all open positions for the account and process them
        $positions = $this->getOpenPositions();

        // info('Open positions: '.$positions->count());

        foreach ($positions as $position) {
            $this->syncOrdersForPosition($position);
        }

        return 0;
    }

    private function syncOrdersForPosition(Position $position)
    {
        foreach ($position
            ->orders
            /*
            ->where('type', '<>', 'MARKET')
            */
            ->whereNotNull('exchange_order_id') as $order) {
            CoreJobQueue::create([
                'class' => SyncOrderJob::class,
                'queue' => 'orders',

                'arguments' => [
                    'orderId' => $order->id,
                ],
            ]);
        }
    }

    private function getOpenPositions()
    {
        return Position::opened()
            ->with('orders')
            ->get();
    }
}
