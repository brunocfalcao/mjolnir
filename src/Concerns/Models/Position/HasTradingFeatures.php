<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasTradingFeatures
{
    // Does this position have all limit orders filled?
    public function hasAllLimitOrdersFilled()
    {
        $this->load('orders');

        // Count filled LIMIT and MARKET-MAGNET orders
        $filledLimitCount = $this->orders
        ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
        ->where('status', 'FILLED')
        ->count();

        // Check if any MARKET order is FILLED → in that case, we can’t say "all LIMITs filled"
        $hasFilledMarketOrder = $this->orders
        ->where('type', 'MARKET')
        ->where('status', 'FILLED')
        ->isNotEmpty();

        return !$hasFilledMarketOrder && $filledLimitCount == $this->total_limit_orders;
    }

    public function syncOrders()
    {
        foreach ($this->orders as $order) {
            $order->apiSync();
        }
    }
}
