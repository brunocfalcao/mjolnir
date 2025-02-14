<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class PositionApiObserver
{
    public function creating(Position $position): void
    {
        // Assign a UUID before creating the order
        $position->uuid = (string) Str::uuid();
    }

    public function updated(Position $position)
    {
        if ($position->wasChanged('last_mark_price')) {
            /**
             * Verify if we need to magnetize an order.
             */
            $magnetOrder = $position->orders()
            ->where('type', 'LIMIT')
            ->where('status', 'NEW')
            ->when($position->direction == 'LONG', function ($query) use ($position) {
                return $query->where('orders.magnet_activation_price', '>=', $position->last_mark_price);
            })
                ->when($position->direction == 'SHORT', function ($query) use ($position) {
                    return $query->where('orders.magnet_activation_price', '<=', $position->last_mark_price);
                })
                ->first();

            if ($magnetOrder) {
                $magnetOrder->withoutEvents(function () use ($magnetOrder) {
                    $magnetOrder->update(['is_magnetized' => true]); // Removed 'orders.' prefix
                });

                User::admin()->get()->each(function ($user) use ($position) {
                        $user->pushover(
                            message: "Magnet ACTIVATED for position {$position->parsedTradingPair}, ID: {$position->id} at price {$position->last_mark_price}",
                            title: "Magnet ACTIVATED for position {$position->parsedTradingPair}",
                            applicationKey: 'nidavellir_positions'
                        );
                });
            }

            // Ensure $magnetOrder is not null before accessing properties
            if ($magnetOrder && $magnetOrder->is_magnetized) {
                if (($magnetOrder->side == 'BUY' && $magnetOrder->magnet_trigger_price <= $position->last_mark_price) ||
                ($magnetOrder->side == 'SELL' && $magnetOrder->magnet_trigger_price >= $position->last_mark_price)) {
                    User::admin()->get()->each(function ($user) use ($position) {
                        $user->pushover(
                            message: "Magnet TRIGGERED for position {$position->parsedTradingPair}, ID: {$position->id} at price {$position->last_mark_price}",
                            title: "Magnet TRIGGERED for position {$position->parsedTradingPair}",
                            applicationKey: 'nidavellir_positions'
                        );
                    });
                }
            }
        }
    }
}
