<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasTradingFeatures
{
    public function atLeastOneLimitOrderFilled()
    {
        $this->load('orders');

        return $this->orders
            ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
            ->where('status', 'FILLED')
            ->count() >= 1;
    }

    // Does this position have all limit orders filled?
    public function hasAllLimitOrdersFilled()
    {
        $this->load('orders');

        $filledLimitCount = $this->orders
            ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
            ->where('status', 'FILLED')
            ->count();

        return $filledLimitCount == $this->total_limit_orders;
    }

    public function syncOrders()
    {
        foreach ($this->orders as $order) {
            $order->apiSync();
        }
    }
}
