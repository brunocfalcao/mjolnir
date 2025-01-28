<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait __HasWAPFeatures
{
    public function calculateWAP()
    {
        // Fetch all FILLED LIMIT orders for this position
        $orders = $this->orders()
            ->where('status', 'FILLED')
            ->whereIn('type', ['MARKET', 'LIMIT'])
            ->get();

        // Ensure there are orders to calculate WAP
        if ($orders->isEmpty()) {
            return [
                'quantity' => null,
                'price' => null,
            ]; // Return null for both quantity and price if no relevant orders
        }

        // Calculate WAP using the formula
        $totalWeightedPrice = 0;
        $totalQuantity = 0;

        foreach ($orders as $order) {
            $totalWeightedPrice += $order->quantity * $order->price;
            $totalQuantity += $order->quantity;
        }

        // Avoid division by zero
        if ($totalQuantity == 0) {
            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        // Calculate WAP price
        $wapPrice = $totalWeightedPrice / $totalQuantity;

        // Get profit percentage. E.g: 0.330
        $profitPercentage = $this->profit_percentage;

        // Add the Profit % on top of it.
        if ($this->direction == 'LONG') {
            // Add profit for LONG positions
            $wapPrice = $wapPrice * (1 + $profitPercentage / 100);
        } elseif ($this->direction == 'SHORT') {
            // Subtract profit for SHORT positions
            $wapPrice = $wapPrice * (1 - $profitPercentage / 100);
        }

        // TODO: Get position amount, and use that on the quantity.

        // Return total quantity and WAP price as an array, and format both numbers.
        return [
            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
        ];
    }
}
