<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\ModifyOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class OrderApiObserver
{
    public function creating(Order $order): void
    {
        // Assign a UUID before creating the order
        $order->uuid = (string) Str::uuid();
    }

    public function updated(Order $order): void
    {
        /**
         * Get all status variables.
         */
        $statusChanged = false;
        $priceChanged = false;
        $quantityChanged = false;

        if ($order->getOriginal('status') != $order->status) {
            $statusChanged = true;
        }

        if ($order->getOriginal('price') != $order->price) {
            $priceChanged = true;
        }

        if ($order->getOriginal('quantity') != $order->quantity) {
            $quantityChanged = true;
        }

        // Get profit order.
        $profitOrder = $order->position->orders->firstWhere('type', 'PROFIT');

        if ($priceChanged || $quantityChanged) {
            CoreJobQueue::create([
                'class' => ModifyOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                    'quantity' => $order->getOriginal('quantity'),
                    'price' => $order->getOriginal('price'),
                ],
            ]);
        }
    }
}
