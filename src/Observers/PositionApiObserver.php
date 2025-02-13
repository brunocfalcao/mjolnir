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
                User::admin()->get()->each(function ($user) use ($position, $magnetOrder) {
                    $user->pushover(
                        message: "Magnet activated for position {$position->parsedTradingPair}, ID: {$position->id} at price {$magnetOrder->magnet_activation_price}",
                        title: "Magnet activated for position {$position->parsedTradingPair}",
                        applicationKey: 'nidavellir_positions'
                    );
                });
            }
        }
    }
}
