<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Support\RealChanges;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class _OrderApiObserver
{
    /**
     * Handle the "creating" event.
     */
    public function creating(Order $order): void
    {
        // Assign a UUID before creating the order
        $order->uuid = (string) Str::uuid();
    }

    /**
     * Handle the "updated" event.
     */
    public function updated(Order $order): void
    {
        // Use RealChanges to determine meaningful changes
        $changes = new RealChanges($order);

        if (! empty($changes->all())) {
            if ($order->type != 'PROFIT') {
                // Obtain the profit order. If it's not filled, then we should consider the order active.
                $profitOrder = $order->position->orders->firstWhere('type', 'PROFIT');
            } else {
                $profitOrder = $order;
            }

            // Run WAP.
            if ($profitOrder->status == 'FILLED') {
                // The profit order is still active. We can check these order changes.
                if ($profitOrder->status == 'NEW') {
                    // Order is still active.

                    // Status-related activities
                    if ($changes->wasChanged('price') || $changes->wasChanged('quantity')) {
                        // Redo the order.
                        CoreJobQueue::create([
                            'class' => RefreshOrder::class,
                            'queue' => 'orders',
                            'arguments' => [
                                'orderId' => $order->id,
                            ],
                        ]);
                    }

                    if ($changes->wasChanged('quantity')) {
                        info('Order ID '.$order->id.' quantity changed from '.$order->getOriginal('quantity').' to '.$order->quantity);
                    }

                    if ($changes->wasChanged('status')) {
                        if ($order->getOriginal('status') == 'NEW' && $order->status == 'CANCELLED') {
                            // Redo the order.
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
            }
        }
    }

    /**
     * Format the real changes into a human-readable string.
     */
    private function formatRealChanges(array $changes): string
    {
        // Debugging to confirm realChanges
        // info('Formatting real changes: ' . json_encode($changes));

        $formattedChanges = [];

        foreach ($changes as $attribute => $values) {
            $formattedChanges[] = "{$attribute}: old value: {$values['old']}, new value: {$values['new']}";
        }

        return implode("\n", $formattedChanges); // Join changes with a newline
    }
}
