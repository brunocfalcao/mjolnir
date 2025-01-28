<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use Nidavellir\Thor\Models\User;

trait HasWAPFeatures
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

        /**
         * Lets verify if the total quantity matches the total amount from
         * the position on the exchange. If not, we take priority from
         * the total position amount from the exchange.
         */
        info('== 1 ==');
        // Get position amount, and use that on the quantity.
        $apiResponse = $this->account->apiQueryPositions();
        info('== 2 ==');
        // Get sanitized positions, key = pair.
        $positions = $apiResponse->result;
        info('== 3 ==');
        if (array_key_exists($this->parsedTradingPair, $positions)) {
            // We have a position. Lets place a contrary order to close it.
            $positionFromExchange = $positions[$this->parsedTradingPair];
            info('== 4 ==');
            // Obtain position amount.
            $positionQuantity = abs($positionFromExchange['positionAmt']);
            info('== 5 ==');
            // Is there a difference between both?
            if ($positionQuantity != $totalQuantity) {
                info('== 6 ==');
                // Pushover to inform.
                User::admin()->get()->each(function ($user) use ($positionQuantity, $totalQuantity) {
                    $user->pushover(
                        message: "WAP quantity difference (Position {$this->parsedTradingPair} ID: {$this->id}) - Exchange quantity: {$positionQuantity}, DB total orders (M+L) quantity: {$totalQuantity}",
                        title: 'WAP quantity difference alert',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
                info('== 7 ==');
                // Give priority to position quantity from the exchange.
                $totalQuantity = $positionQuantity;
                info('== 8 ==');
            }
        }
        info('== 9 ==');
        info('Exchange quantity: ' . $positionQuantity . ' vs total limit orders quantity: ' . $totalQuantity);

        // Avoid division by zero
        if ($totalQuantity == 0) {
            info('== 10 ==');
            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        info('== 11 ==');
        // Calculate WAP price.
        $wapPrice = $totalWeightedPrice / $totalQuantity;

        // Get profit percentage. E.g: 0.330
        $profitPercentage = $this->profit_percentage;
        info('== 12 ==');

        // Add the Profit % on top of it.
        if ($this->direction == 'LONG') {
            info('== 13 ==');
            // Add profit for LONG positions
            $wapPrice = $wapPrice * (1 + $profitPercentage / 100);
        } elseif ($this->direction == 'SHORT') {
            info('== 14 ==');
            // Subtract profit for SHORT positions
            $wapPrice = $wapPrice * (1 - $profitPercentage / 100);
        }

        info('== 15 ==');
        // Return total quantity and WAP price as an array, and format both numbers.
        return [
            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
        ];
    }
}
