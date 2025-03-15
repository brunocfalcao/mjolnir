<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasTradingFeatures
{
    // Does this position have all limit orders filled?
    public function hasAllLimitOrdersFilled()
    {
        $this->load('orders');

        return $this->orders->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
            ->where('status', 'FILLED')
            ->count() == $this->total_limit_orders;
    }
}
