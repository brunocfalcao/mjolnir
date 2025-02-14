<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use Nidavellir\Thor\Models\User;

trait HasMagnetizationFeatures
{
    public function assessMagnetActivation()
    {
        $magnetOrder = $this->orders()
            ->where('type', 'LIMIT')
            ->where('status', 'NEW')
            ->when($this->direction == 'LONG', function ($query) {
                return $query->where('orders.magnet_activation_price', '>=', $this->last_mark_price);
            })
            ->when($this->direction == 'SHORT', function ($query) {
                return $query->where('orders.magnet_activation_price', '<=', $this->last_mark_price);
            })
            ->first();

        if ($magnetOrder) {
            $magnetOrder->withoutEvents(function () use ($magnetOrder) {
                $magnetOrder->update(['is_magnetized' => true]);
            });

            User::admin()->get()->each(function ($user) {
                $user->pushover(
                    message: "Magnet ACTIVATED for position {$this->parsedTradingPair}, ID: {$this->id} at price {$this->last_mark_price}",
                    title: "Magnet ACTIVATED for position {$this->parsedTradingPair}",
                    applicationKey: 'nidavellir_positions'
                );
            });
        }
    }

    public function assessMagnetTrigger()
    {
        /**
         * A magnet trigger will execute the following:
         * Cancel the limit order that is part of this magnet.
         * Create a market order with exactly the same quantity as the
         * limit order that was cancelled, type 'LIMIT-MAGNET'.
         */
        foreach ($this->orders()->where('is_magnetized')->get() as $magnetOrder) {
            if (($magnetOrder->side == 'BUY' && $magnetOrder->magnet_trigger_price <= $this->last_mark_price) ||
            ($magnetOrder->side == 'SELL' && $magnetOrder->magnet_trigger_price >= $this->last_mark_price)) {
                User::admin()->get()->each(function ($user) {
                    $user->pushover(
                        message: "Magnet TRIGGERED for position {$this->parsedTradingPair}, ID: {$this->id} at price {$this->last_mark_price}",
                        title: "Magnet TRIGGERED for position {$this->parsedTradingPair}",
                        applicationKey: 'nidavellir_positions'
                    );
                });
            }
        }
    }
}
