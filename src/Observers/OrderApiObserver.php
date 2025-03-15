<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Exceptions\JustEndException;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\ModifyOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
use Nidavellir\Mjolnir\Jobs\Processes\UpdateWAP\UpdateWAPLifecycleJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class OrderApiObserver
{
    public function creating(Order $order): void
    {
        $order->uuid = (string) Str::uuid();
        $order->load('position');

        $this->checkExcessOrder($order, 'LIMIT', ['LIMIT', 'MARKET-MAGNET'], ['NEW', 'FILLED', 'PARTIALLY_FILLED'], $order->position->total_limit_orders);
        $this->checkExcessOrder($order, 'MARKET', ['MARKET'], ['NEW', 'FILLED'], 1);
        $this->checkExcessOrder($order, 'PROFIT', ['PROFIT'], ['NEW', 'FILLED', 'PARTIALLY_FILLED'], 1);
        $this->checkExcessOrder($order, 'MARKET-CANCEL', ['MARKET-CANCEL'], ['NEW', 'FILLED'], 1);
        $this->checkExcessOrder($order, 'STOP-MARKET', ['STOP-MARKET'], ['NEW', 'FILLED'], 1);
    }

    protected function checkExcessOrder(Order $order, string $checkType, array $filterTypes, array $statuses, int $maxAllowed): void
    {
        if ($order->type != $checkType) {
            return;
        }

        $count = $order->position->orders
            ->whereIn('type', $filterTypes)
            ->whereIn('status', $statuses)
            ->count();

        if ($count >= $maxAllowed) {
            throw new JustEndException(sprintf(
                'Excessively creating one more %s order for position %s (ID: %s). Aborting creation.',
                $checkType,
                $order->position->parsedTradingPair,
                $order->position->id
            ));
        }
    }

    public function updated(Order $order): void
    {
        // Skip processing if the position is not active
        if ($order->position->status != 'active') {
            return;
        }

        // Skip processing if the observer is flagged to be skipped
        if ($order->skip_observer) {
            $order->withoutEvents(function () use ($order) {
                $order->update(['skip_observer' => false]);
            });

            return;
        }

        // Skip processing if a PROFIT order transitions from NEW to PARTIALLY_FILLED
        if ($order->type == 'PROFIT' &&
            $order->getOriginal('status') == 'NEW' &&
            $order->status == 'PARTIALLY_FILLED'
        ) {
            return;
        }

        // Skip observers while a WAP is being processed.
        if ($order->type == 'PROFIT' && $order->position->wap_triggered) {
            return;
        }

        // Skip processing for STOP-MARKET, MARKET, and MARKET-CANCEL orders
        if (in_array($order->type, ['STOP-MARKET', 'MARKET', 'MARKET-CANCEL'])) {
            return;
        }

        // Load additional relationships
        $order->load(['position.orders', 'position.exchangeSymbol.symbol']);
        $token = $order->position->exchangeSymbol->symbol->token;
        $statusChanged = $this->hasChanged($order, 'status');
        $priceChanged = $this->hasChanged($order, 'price');
        $quantityChanged = $this->hasChanged($order, 'quantity');
        $profitOrder = $order->position->profitOrder();

        // If LIMIT order price or quantity changed, trigger a ModifyOrderJob
        if ($order->type == 'LIMIT' && $order->status == 'NEW' && ($priceChanged || $quantityChanged)) {
            CoreJobQueue::create([
                'class' => ModifyOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                    'quantity' => $order->getOriginal('quantity'),
                    'price' => $order->getOriginal('price'),
                ],
            ]);

            // Skip the next iteration.
            $order->withoutEvents(function () use ($order) {
                $order->update(['skip_observer' => true]);
            });

            return;
        }

        // If PROFIT order status is FILLED or EXPIRED, trigger ClosePositionLifecycleJob
        if ($order->type == 'PROFIT' && in_array($order->status, ['FILLED', 'EXPIRED'])) {
            CoreJobQueue::create([
                'class' => ClosePositionLifecycleJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $order->position->id,
                ],
            ]);

            return;
        }

        // If order status is CANCELLED and wasn't previously CANCELLED, trigger PlaceOrderJob if conditions met
        if ($order->status == 'CANCELLED' && $order->getOriginal('status') != 'CANCELLED') {
            if (($order->type == 'LIMIT' && $order->magnet_status == 'standby') || $order->type == 'PROFIT') {
                CoreJobQueue::create([
                    'class' => PlaceOrderJob::class,
                    'queue' => 'orders',
                    'arguments' => ['orderId' => $order->id],
                ]);
            }

            // Skip the next iteration.
            $order->withoutEvents(function () use ($order) {
                $order->update(['skip_observer' => true]);
            });

            return;
        }

        // If order status changed to FILLED for LIMIT or MARKET-MAGNET, trigger WAP calculation
        if ($order->status == 'FILLED' &&
            $order->getOriginal('status') != 'FILLED' &&
            in_array($order->type, ['LIMIT', 'MARKET-MAGNET'])
        ) {
            // info("[ApiObserver] - WAP Core Job queued for position {$order->position->id}");

            CoreJobQueue::create([
                'class' => UpdateWAPLifecycleJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'positionId' => $order->position->id,
                ],
                'dispatch_after' => now()->addSeconds(5),
            ]);

            return;
        }
    }

    protected function hasChanged(Order $order, string $attribute): bool
    {
        return $order->wasChanged($attribute)
            && ! empty($order->getOriginal($attribute))
            && $order->getOriginal($attribute) != $order->{$attribute};
    }
}
