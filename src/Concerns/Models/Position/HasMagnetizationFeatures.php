<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasMagnetizationFeatures
{
    public function assessMagnetActivation()
    {
        if ($this->last_mark_price != null) {
            $magnetOrder = $this->orders()
                ->where('type', 'LIMIT')
                ->where('status', 'NEW')
                ->where('magnet_status', 'standby')
                ->when($this->direction == 'LONG', function ($query) {
                    return $query->where('orders.magnet_activation_price', '>=', $this->last_mark_price);
                })
                ->when($this->direction == 'SHORT', function ($query) {
                    return $query->where('orders.magnet_activation_price', '<=', $this->last_mark_price);
                })
                ->first();

            if ($magnetOrder) {
                $this->load('exchangeSymbol');

                $magnetOrder->withoutEvents(function () use ($magnetOrder) {
                    $magnetOrder->update(['magnet_status' => 'activated']);
                });

                return $magnetOrder;
            }
        }

        return null;
    }

    public function assessMagnetTrigger()
    {
        /**
         * A magnet trigger will execute the following:
         * Cancel the limit order that is part of this magnet.
         * Create a market order with exactly the same quantity as the
         * limit order that was cancelled, type 'MARKET-MAGNET'.
         */
        foreach ($this->orders()->where('magnet_status', 'activated')->get() as $magnetOrder) {
            if (($magnetOrder->side == 'BUY' && $magnetOrder->magnet_trigger_price <= $this->last_mark_price) ||
            ($magnetOrder->side == 'SELL' && $magnetOrder->magnet_trigger_price >= $this->last_mark_price)) {
                $this->load('exchangeSymbol');
                $price = api_format_price($this->last_mark_price, $this->exchangeSymbol);

                return $magnetOrder;
            }
        }

        return null;
    }
}
