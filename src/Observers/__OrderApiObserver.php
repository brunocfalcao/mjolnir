<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\ResettleOrderJob;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class __OrderApiObserver
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

        $profitOrder = $order->position->orders->firstWhere('type', 'PROFIT');

        if ($order->wasChanged()) {
            if ($order->wasChanged('price') || $order->wasChanged('quantity')) {
                // Limit order was changed (price, or quantity) without order being filled.

                if ($order->status == 'NEW') {
                    if ($order->getOriginal('price') != $order->price) {
                        info('Price, from '.$order->getOriginal('price').' to '.$order->price);
                    }

                    if ($order->getOriginal('quantity') != $order->quantity) {
                        info('Quantity, from '.$order->getOriginal('quantity').' to '.$order->quantity);
                    }

                    if (($order->wasChanged('quantity') || $order->wasChanged('price'))) {
                        /*
                        CoreJobQueue::create([
                        'class' => ResettleOrderJob::class,
                        'queue' => 'orders',
                        'arguments' => [
                            'orderId' => $order->id,
                        ],
                        ]);
                        */
                    }
                }
            }

            if ($order->wasChanged('status')) {
                if ($order->type == 'PROFIT') {
                    $profitOrder = $order;
                } else {
                    $profitOrder = $order->position->orders->firstWhere('type', 'PROFIT');
                }

                info('Profit Order ID: '.$profitOrder->id);

                // Profit order filled. We can close the position.
                if ($order->status == 'FILLED' && $order->type == 'PROFIT') {
                    info('Profit order filled, position can be closed');
                    CoreJobQueue::create([
                        'class' => ClosePositionLifecycleJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $order->position->id,
                        ],
                    ]);
                }

                // Profit order was cancelled by mistake.
                if ($order->status == 'CANCELLED' && $order->type == 'PROFIT') {
                    info('Profit order cancelled by mistake');
                    CoreJobQueue::create([
                        'class' => PlaceOrderJob::class,
                        'queue' => 'orders',
                        'arguments' => [
                            'orderId' => $order->id,
                        ],
                    ]);
                }

                // Means the trade is still active.
                if ($profitOrder->status == 'NEW' || $profitOrder->status == 'PARTIALLY_FILLED') {
                    // Limit order was cancelled by mistake.
                    info('Profit order cancelled by mistake');
                    if ($order->status == 'CANCELLED') {
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
