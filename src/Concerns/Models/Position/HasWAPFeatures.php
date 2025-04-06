<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait HasWAPFeatures
{
    public function calculateWAP()
    {
        $resync = false;
        $error = '';

        $orders = $this->orders()
            ->where('status', 'FILLED')
            ->whereIn('type', ['MARKET', 'LIMIT', 'MARKET-MAGNET'])
            ->get();

        if ($orders->isEmpty()) {
            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        $totalWeightedPrice = 0;
        $totalQuantity = 0;

        foreach ($orders as $order) {
            $totalWeightedPrice += $order->quantity * $order->price;
            $totalQuantity += $order->quantity;
        }

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

        if ($totalQuantity == 0) {
            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        $wapPrice = $totalWeightedPrice / $totalQuantity;
        $profitPercentage = $this->profit_percentage;

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
