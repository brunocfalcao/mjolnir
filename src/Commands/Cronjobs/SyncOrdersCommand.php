<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\SyncOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class SyncOrdersCommand extends Command
{
    protected $signature = 'mjolnir:sync-orders {--orderId= : Sync only a specific order by ID}';

    protected $description = 'Syncs all orders, and accordingly to the changes, triggers WAP, Close positions, etc';

    public function handle()
    {
        $orderId = $this->option('orderId');

        if ($orderId) {
            $this->syncSpecificOrder($orderId);

            return 0;
        }

        // Fetch all open positions for the account and process them
        $positions = $this->getOpenPositions();

        foreach ($positions as $position) {
            $this->syncOrdersForPosition($position);
        }

        return 0;
    }

    private function syncSpecificOrder($orderId)
    {
        $order = Order::find($orderId);

        if (! $order || ! $order->exchange_order_id) {
            $this->error("Order ID {$orderId} not found or has no exchange_order_id. Aborting...");

            return;
        }

        CoreJobQueue::create([
            'class' => SyncOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $order->id,
            ],
        ]);
    }

    private function syncOrdersForPosition(Position $position)
    {
        foreach ($position->orders->whereNotNull('exchange_order_id') as $order) {
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
