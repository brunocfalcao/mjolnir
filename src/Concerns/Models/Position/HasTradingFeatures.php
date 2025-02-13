<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasTradingFeatures
{
    // Does this position have all limit orders filled?
    public function hasAllLimitOrdersFilled()
    {
        $this->load('orders');

        return $this->orders->where('type', 'LIMIT')
            ->where('status', 'FILLED')
            ->count() == $this->total_limit_orders;
    }

    public function profitOrder()
    {
        return $this->orders
            ->firstWhere('type', 'PROFIT');
    }
}
