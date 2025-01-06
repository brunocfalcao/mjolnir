<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\ModifyOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
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

        // Price or quantity changed? Resettle order quantity and price.
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

        // Profit order status filled? -- Close position.
        if ($order->type == 'PROFIT' && $order->status == 'FILLED') {
            CoreJobQueue::create([
                'class' => ClosePositionLifecycleJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $order->position->id,
                ],
            ]);
        }

        // Order cancelled by mistake? Re-place the order.
        if ($order->status == 'CANCELLED' && $order->getOriginal('status') != 'CANCELLED') {
            CoreJobQueue::create([
                'class' => PlaceOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                ],
            ]);
        }
    }
}
