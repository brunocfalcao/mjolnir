<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\ClosePositionJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class OrderApiObserver
{
    /**
     * Handle the "creating" event.
     */
    public function creating(Order $order): void
    {
        // Assign a UUID before creating the order
        $order->uuid = (string) Str::uuid();
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged()) {
            if ($order->wasChanged('status')) {
                // Profit order filled. We can close the position.
                if ($order->status == 'FILLED' && $order->type == 'PROFIT') {
                    CoreJobQueue::create([
                        'class' => ClosePositionJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $order->position->id,
                        ],
                    ]);
                }
            }
        }
    }
}
