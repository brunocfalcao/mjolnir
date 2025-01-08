<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CalculateWAPAndAdjustProfitOrderJob;
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

        // Non-Profit order price or quantity changed? Resettle order quantity and price.
        if ($priceChanged || $quantityChanged) {
            if ($order->type != 'PROFIT') {
                // Put back the market/limit order back where it was.
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

            // For a profit order we need to verify if it was due to a WAP.
            if ($order->type == 'PROFIT') {
                if (! $order->position->wap_triggered) {
                    // The PROFIT order was manually changed, not due to a WAP.
                    CoreJobQueue::create([
                        'class' => ModifyOrderJob::class,
                        'queue' => 'orders',
                        'arguments' => [
                            'orderId' => $order->id,
                            'quantity' => $order->getOriginal('quantity'),
                            'price' => $order->getOriginal('price'),
                        ],
                    ]);
                } else {
                    // Reset WAP trigger. Do not modify the PROFIT order.
                    $order->position->update([
                        'wap_triggered' => false,
                    ]);
                }
            }
        }

        // Profit order status filled or expired? -- Close position. All done.
        if ($order->type == 'PROFIT' && ($order->status == 'FILLED' || $order->status == 'EXPIRED')) {
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

        // Limit order filled?
        if ($order->status == 'FILLED' && $order->getOriginal('status') != 'FILLED' && $order->type == 'LIMIT') {
            // WAP calculation.
            info('WAP calculation triggered');
            CoreJobQueue::create([
                'class' => CalculateWAPAndAdjustProfitOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $profitOrder->id,
                ],
            ]);
        }
    }
}
