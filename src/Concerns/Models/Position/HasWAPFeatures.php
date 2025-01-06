<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasWAPFeatures
{
    public function calculateWAP()
    {
        // Fetch all FILLED LIMIT orders for this position
        $orders = $this->orders()
            ->where('status', 'FILLED')
            ->where('type', 'LIMIT')
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

        // Return total quantity and WAP price as an array, and format both numbers.
        return [
            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
        ];
    }
}
