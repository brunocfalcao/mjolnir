<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use Nidavellir\Thor\Models\User;

trait HasWAPFeatures
{
    public function calculateWAP()
    {
        // Set resync and error to defaults.
        $resync = false;
        $error = '';

        // Fetch all FILLED LIMIT orders for this position
        $orders = $this->orders()
            ->where('status', 'FILLED')
            ->whereIn('type', ['MARKET', 'LIMIT', 'MARKET-MAGNET'])
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
        $this->loadMissing('account');
        // Get position amount, and use that on the quantity.
        $apiResponse = $this->account->apiQueryPositions();
        // Get sanitized positions, key = pair.
        $positions = $apiResponse->result;
        if (array_key_exists($this->parsedTradingPair, $positions)) {
            // We have a position. Lets place a contrary order to close it.
            $positionFromExchange = $positions[$this->parsedTradingPair];
            // Obtain position amount.
            $positionQuantity = abs($positionFromExchange['positionAmt']);

            // Is there a difference between both? -- Cast to strings.
            if ((string) $positionQuantity != (string) $totalQuantity) {
                User::admin()->get()->each(function ($user) use ($positionQuantity, $totalQuantity) {
                    $user->pushover(
                        message: "WAP difference ({$this->parsedTradingPair} ID: {$this->id}) - Exchange: {$positionQuantity}, DB (M+L): {$totalQuantity}",
                        title: 'WAP quantity difference alert',
                        applicationKey: 'nidavellir_warnings'
                    );
                });

                $difference = abs($positionQuantity - $totalQuantity);

                // Max gap threshold: 15%.
                $threshold = abs($positionQuantity) * 0.15;

                if ($difference > $threshold) {
                    // Something happened, we need to request a resync.
                    $resync = true;
                    $error = 'The difference percentage between totalQuantity and positionQuantity exceeds threshold';
                }

                // Give priority to position quantity from the exchange.
                $totalQuantity = $positionQuantity;
            }
        }

        // Avoid division by zero
        if ($totalQuantity == 0) {
            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        // Calculate WAP price.
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

        // Return total quantity and WAP price as an array, and format both numbers.
        return [
            'resync' => $resync,
            'error' => $error,
            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
        ];
    }
}
