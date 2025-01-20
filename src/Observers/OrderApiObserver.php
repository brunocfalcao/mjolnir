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
        $order->load('position.orders');

        // We just api observe for active orders.
        if ($order->position->status != 'active') {
            return;
        }

        if ($order->type == 'MARKET') {
            return;
        }

        /**
         * Get all status variables.
         */
        $statusChanged = false;
        $priceChanged = false;
        $quantityChanged = false;

        $order->load('position.exchangeSymbol.symbol');

        $token = $order->position->exchangeSymbol->symbol->token;

        if ($order->wasChanged('status') && ! empty($order->getOriginal('status')) && $order->getOriginal('status') != $order->status) {
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - status changed from '.$order->getOriginal('status').' to '.$order->status);
            $statusChanged = true;
        }

        if ($order->wasChanged('price') && ! empty($order->getOriginal('price')) && $order->getOriginal('price') != $order->price) {
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - price changed from '.$order->getOriginal('price').' to '.$order->price);
            $priceChanged = true;
        }

        if ($order->wasChanged('quantity') && ! empty($order->getOriginal('quantity')) && $order->getOriginal('quantity') != $order->quantity) {
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - quantity changed from '.$order->getOriginal('quantity').' to '.$order->quantity);
            $quantityChanged = true;
        }

        // Get profit order.
        $profitOrder = $order->position->orders->firstWhere('type', 'PROFIT');

        // Pay attention if the position was just closed (position.status = 'closing'). Then act.
        $isClosing = $order->position->status == 'closing';

        // Non-Profit order price or quantity changed? Resettle order quantity and price.
        if (($priceChanged || $quantityChanged) && ! $isClosing) {
            if ($order->type != 'PROFIT') {
                // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Order had a price and/or quantity changed. Resettling order');
                // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering ModifyOrderJob::class');
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
                    // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Profit order changed and it was not due to WAP. Resettling order');
                    // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering ModifyOrderJob::class');
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
                    info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Setting wap_triggered back to false');
                    $order->position->update([
                        'wap_triggered' => false,
                    ]);
                }
            }
        }

        // Profit order status filled or expired? -- Close position. All done.
        if ($order->type == 'PROFIT' && ($order->status == 'FILLED' || $order->status == 'EXPIRED')) {
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Profit order is filled or expired. We can close the position');
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering ClosePositionLifecycleJob::class');
            CoreJobQueue::create([
                'class' => ClosePositionLifecycleJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $order->position->id,
                ],
            ]);
        }

        // Order cancelled by mistake? Re-place the order.
        if ($order->status == 'CANCELLED' && $order->getOriginal('status') != 'CANCELLED' && ! $isClosing) {
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Order canceled by mistake. Recreating order');
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering PlaceOrderJob::class');
            CoreJobQueue::create([
                'class' => PlaceOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                ],
            ]);
        }

        // Limit order filled or partially filled? -- Compute WAP.
        if (($order->status == 'FILLED' || $order->status == 'PARTIALLY_FILLED') && $order->getOriginal('status') != 'FILLED' && $order->type == 'LIMIT') {
            // WAP calculation.
            info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Limit order filled or partially filled, recalculating WAP and readjusting Profit order');
            info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering CalculateWAPAndAdjustProfitOrderJob::class');

            CoreJobQueue::create([
                'class' => CalculateWAPAndAdjustProfitOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $profitOrder->id,
                    'originalPrice' => $order->getOriginal('price'),
                    'originalQuantity' => $order->getOriginal('quantity'),
                ],
            ]);
        }
    }
}
