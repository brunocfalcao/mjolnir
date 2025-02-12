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
        return;

        if ($position->wasChanged('last_mark_price') && $position->magnet_activation_price != null) {
            $magnetOrder = $position->orders()
                ->where('type', 'LIMIT')
                ->where('status', 'NEW')
                ->when($position->direction == 'LONG', function ($query) use ($position) {
                    return $query->where($position->last_mark_price >= 'magnet_activation_price');
                })
                ->when($position->direction == 'SHORT', function ($query) use ($position) {
                    return $query->where($position->last_mark_price <= 'magnet_activation_price');
                })->first();

            if ($magnetOrder) {
                $magnetOrder->update(['is_magnetized' => true]);
                User::admin()->get()->each(function ($user) use ($position) {
                    $user->pushover(
                        message: "Magnet ACTIVATED for position {$position->parsedTradingPair}, ID: {$position->id} at price {$position->last_mark_price}",
                        title: "Magnet ACTIVATED for position {$position->parsedTradingPair}",
                        applicationKey: 'nidavellir_positions'
                    );
                });
            }

            if ($magnetOrder->is_magnetized) {
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
