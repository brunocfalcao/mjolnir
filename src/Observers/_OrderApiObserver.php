<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Exceptions\JustEndException;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CalculateWAPAndAdjustProfitOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\ModifyOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class _OrderApiObserver
{
    public function creating(Order $order): void
    {
        // Assign a UUID before creating the order
        $order->uuid = (string) Str::uuid();

        /**
         * Check if we are creating more orders than we should.
         * If so, then we need to raise a JustEndException so the
         * order is not exceptionable-resolvable by the BaseQueuableJob.
         */
        $order->load('position');

        // Do we already have all the limit orders and are we creating one more?
        $totalEligibleOrders = $order->position
            ->orders
            ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
            ->whereIn('status', ['NEW', 'FILLED', 'PARTIALLY_FILLED'])
            ->count();

        if ($totalEligibleOrders == $order->position->total_limit_orders && $order->type == 'LIMIT') {
            throw new JustEndException("Excessively creating one more LIMIT order for position {$order->position->parsedTradingPair} (ID: {$order->position->id}). Aborting creation.");
        }

        $eligibleMarketOrder = $order->position
            ->orders
            ->where('type', 'MARKET')
            ->whereIn('status', ['NEW', 'FILLED'])
            ->count();

        if ($order->type == 'MARKET' && $eligibleMarketOrder >= 1) {
            throw new JustEndException("Excessively creating one more MARKET order for position {$order->position->parsedTradingPair} (ID: {$order->position->id}). Aborting creation.");
        }

        $eligibleProfitOrder = $order->position
            ->orders
            ->where('type', 'PROFIT')
            ->whereIn('status', ['NEW', 'FILLED', 'PARTIALLY_FILLED'])
            ->count();

        if ($order->type == 'PROFIT' && $eligibleProfitOrder >= 1) {
            throw new JustEndException("Excessively creating one more PROFIT (Take Profit Market) order for position {$order->position->parsedTradingPair} (ID: {$order->position->id}). Aborting creation.");
        }

        $eligibleMarketCancelOrder = $order->position
            ->orders
            ->where('type', 'MARKET-CANCEL')
            ->whereIn('status', ['NEW', 'FILLED'])
            ->count();

        if ($order->type == 'MARKET-CANCEL' && $eligibleMarketCancelOrder >= 1) {
            throw new JustEndException("Excessively creating one more MARKET-CANCEL order for position {$order->position->parsedTradingPair} (ID: {$order->position->id}). Aborting creation.");
        }

        $eligibleStopMarketOrder = $order->position
            ->orders
            ->where('type', 'STOP-MARKET')
            ->whereIn('status', ['NEW', 'FILLED'])
            ->count();

        // info($order->type);
        // info($eligibleStopMarketOrder);

        if ($order->type == 'STOP-MARKET' && $eligibleStopMarketOrder >= 1) {
            throw new JustEndException("Excessively creating one more STOP-MARKET order for position {$order->position->parsedTradingPair} (ID: {$order->position->id}). Aborting creation.");
        }
    }

    public function updated(Order $order): void
    {
        // info("-= UPDATED observer Order ID {$order->id} started =-");

        // Just check active positions and non-market/market-cancel orders.
        if ($order->position->status != 'active') {
            // info('Order position is not on active state. Returning.');

            return;
        }

        /**
         * If we need to skip this observer on this order, then we skip it
         * and we activate the observer again.
         */
        if ($order->skip_observer) {
            // info('Order have a skip observer, resetting and returning.');
            $order->withoutEvents(function () use ($order) {
                // Perform the query or operation here
                $order->update(['skip_observer' => false]);
            });

            return;
        }

        /**
         * Skip cases where the PROFIT order was NEW and now is PARTIALLY FILLED. This means
         * that the profit order is starting to be filled, but we don't want
         * to change the profit order because of this observer trigger.
         */
        if ($order->type == 'PROFIT' && $order->getOriginal('status') == 'NEW' && $order->status == 'PARTIALLY_FILLED') {
            // info('Order PROFIT originally with status NEW and not PARTIALLY_FILLED. Returning.');

            return;
        }

        // Skip observers for stop-market, market and market-cancel orders.
        if ($order->type == 'STOP-MARKET' || $order->type == 'MARKET' || $order->type == 'MARKET-CANCEL') {
            return;
        }

        $order->load(['position.orders', 'position.exchangeSymbol.symbol']);

        /**
         * Get all status variables.
         */
        $statusChanged = false;
        $priceChanged = false;
        $quantityChanged = false;

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

        // The last profit order is always the latest one created (higher ID).
        $profitOrder = $order->position
            ->orders
            ->where('type', 'PROFIT')
            ->sortByDesc('id')
            ->first();

        // info("Current ACTIVE profit Order ID: {$profitOrder->id}");

        // Non-Profit order price or quantity changed? Resettle order quantity and price.
        if ($order->type == 'LIMIT' && ($priceChanged || $quantityChanged)) {
            if ($order->type != 'PROFIT') {
                // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Order had a price and/or quantity changed. Resettling order price from '.$order->price.' to '.$order->getOriginal('price'));
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

                // Temporarily disable the observer for the next instance call.
                // info('Updating order to skip observer on the next iteration.');
                $order->withoutEvents(function () use ($order) {
                    $order->update(['skip_observer' => true]);
                });

                info('-= UPDATED observer ended =-');

                return;
            }

            // If a WAP was triggered, then we need to reset the WAP trigger.
            if ($order->type == 'PROFIT' && $order->position->wap_triggered) {
                $order->position->update([
                    'wap_triggered' => false,
                ]);

                // info('-= UPDATED observer ended =-');

                return;
            }

            /**
             * TODO: If a PROFIT order was cancelled, then it means we will need to
             * search the new profit order created by the exchange, cancel that one
             * and create a new one. This is for the case that someone changed the
             * price on the take profit market order, and we need to resettle it
             * back to where it was.
             */
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

            // info('-= UPDATED observer ended =-');

            return;
        }

        // Order cancelled by mistake? Re-place the order (except standby magnetized orders, those will be resettled also).
        if ($order->status == 'CANCELLED' && $order->getOriginal('status') != 'CANCELLED') {
            if (($order->type == 'LIMIT' && $order->magnet_status == 'standby') || $order->type == 'PROFIT') {
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

            // info('-= UPDATED observer ended =-');

            return;
        }

        // Limit order filled or partially filled? -- Compute WAP.
        if (($order->status == 'FILLED') && $order->getOriginal('status') != 'FILLED' && ($order->type == 'LIMIT' || $order->type == 'MARKET-MAGNET')) {
            // WAP calculation.
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Limit order filled or partially filled, recalculating WAP and readjusting Profit order');
            // info('[OrderApiObserver] '.$token.' - '.$order->type.' Order ID: '.$order->id.' - Triggering CalculateWAPAndAdjustProfitOrderJob::class');

            CoreJobQueue::create([
                'class' => CalculateWAPAndAdjustProfitOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $profitOrder->id,
                    'originalPrice' => $profitOrder->price,
                    'originalQuantity' => $profitOrder->quantity,
                ],
                // Lets give time for the exchange to sync everything.
                'dispatch_after' => now()->addSeconds(15),
            ]);

            // info('-= UPDATED observer ended =-');

            return;
        }

        // info('-= UPDATED observer ended =-');
    }
}
