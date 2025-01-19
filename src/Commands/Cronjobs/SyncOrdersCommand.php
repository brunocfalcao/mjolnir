<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\SyncOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class SyncOrdersCommand extends Command
{
    protected $signature = 'mjolnir:sync-orders';

    protected $description = 'Syncs all orders, and accordingly to the changes, triggers WAP, Close positions, etc';

    public function handle()
    {
        // File::put(storage_path('logs/laravel.log'), '');
        // DB::table('core_job_queue')->truncate();

        // Fetch all open positions for the account and process them
        $positions = $this->getOpenPositions();

        foreach ($positions as $position) {
            $this->syncOrdersForPosition($position);
        }

        return 0;
    }

    private function syncOrdersForPosition(Position $position)
    {
        $position->load('account');
        $apiPositions = $position->account->apiQueryPositions();

        // Update position to closing so the Api Order observer will know about it.
        if (! array_key_exists($position->parsedTradingPair, $apiPositions)) {
            $this->position->updateToClosing();
        }

        foreach ($position
            ->orders
            ->whereNotNull('exchange_order_id')
            ->where('status', '<>', 'FILLED') as $order) {
            CoreJobQueue::create([
                'class' => SyncOrderJob::class,
                'queue' => 'cronjobs',

                'arguments' => [
                    'orderId' => $order->id,
                ],
            ]);
        }
    }

    private function getOpenPositions()
    {
        return Position::opened()
            ->with('orders') // Eager load orders to prevent N+1 queries
            ->get();
    }
}
