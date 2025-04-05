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
            ];
        }

        // Calculate WAP using the formula
        $totalWeightedPrice = 0;
        $totalQuantity = 0;

        foreach ($orders as $order) {
            $totalWeightedPrice += $order->quantity * $order->price;
            $totalQuantity += $order->quantity;
        }

        /**
         * Verify if the total quantity matches the amount on the exchange.
         */
        $this->load('account');
        $apiResponse = $this->account->apiQueryPositions();
        $positions = $apiResponse->result;
        $positionFromExchange = null;

        if (array_key_exists($this->parsedTradingPair, $positions)) {
            $positionFromExchange = $positions[$this->parsedTradingPair];
            $positionQuantity = abs($positionFromExchange['positionAmt']);

            if ((string) $positionQuantity != (string) $totalQuantity) {
                $difference = abs($positionQuantity - $totalQuantity);
                $threshold = abs($positionQuantity) * 0.15;

                if ($difference > $threshold) {
                    $resync = true;
                    $error = 'The difference percentage between totalQuantity and positionQuantity exceeds threshold';
                }

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

        // Base WAP calculation
        $wapPrice = $totalWeightedPrice / $totalQuantity;
        $profitPercentage = $this->profit_percentage;

        /**
         * If all LIMIT orders were filled, use adjusted breakEvenPrice.
         */
        if ($this->hasAllLimitOrdersFilled() && $positionFromExchange != null) {
            $breakEven = $positionFromExchange['breakEvenPrice'];
            $wapPrice = $this->adjustForSlippage($breakEven, $this->direction, slippagePercent: 0.03);
        } else {
            if ($this->direction == 'LONG') {
                $wapPrice = $wapPrice * (1 + $profitPercentage / 100);
            } elseif ($this->direction == 'SHORT') {
                $wapPrice = $wapPrice * (1 - $profitPercentage / 100);
            }
        }

        return [
            'resync' => $resync,
            'error' => $error,
            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
        ];
    }

    /**
     * Adjust break-even price with a small slippage margin to avoid red PnL.
     */
    public function adjustForSlippage($price, $direction, $slippagePercent = 0.03)
    {
        if ($slippagePercent == 0) {
            return $price;
        }

        $adjustment = $price * ($slippagePercent / 100);

        return match (strtoupper($direction)) {
            'LONG' => $price + $adjustment,
            'SHORT' => $price - $adjustment,
            default => $price,
        };
    }
}
