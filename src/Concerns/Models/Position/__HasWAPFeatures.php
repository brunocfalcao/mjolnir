<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

trait __HasWAPFeatures
{
    public function calculateWAP()
    {
        info('[WAP] Starting WAP calculation for position ID: '.$this->id);

        $resync = false;
        $error = '';

        // Fetch orders
        $orders = $this->orders()
            ->where('status', 'FILLED')
            ->whereIn('type', ['MARKET', 'LIMIT', 'MARKET-MAGNET'])
            ->get();

        info('[WAP] Fetched FILLED orders: '.json_encode($orders->map(fn ($o) => [
            'id' => $o->id,
            'type' => $o->type,
            'quantity' => $o->quantity,
            'price' => $o->price,
        ])));

        if ($orders->isEmpty()) {
            info('[WAP] No orders found, returning nulls');

            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        $totalWeightedPrice = 0;
        $totalQuantity = 0;

        foreach ($orders as $order) {
            info("[WAP] Math Numerator - {$order->quantity} x {$order->price} = ".$order->quantity * $order->price);
            $totalWeightedPrice += $order->quantity * $order->price;

            info("[WAP] Math Denominator (quantity) - {$order->quantity}");
            $totalQuantity += $order->quantity;
        }

        info("[WAP] Total Weighted Price: {$totalWeightedPrice}, Total Quantity: {$totalQuantity}");

        $this->load('account');
        $apiResponse = $this->account->apiQueryPositions();
        $positions = $apiResponse->result;

        $positionFromExchange = null;

        if (array_key_exists($this->parsedTradingPair, $positions)) {
            $positionFromExchange = $positions[$this->parsedTradingPair];
            $positionQuantity = abs($positionFromExchange['positionAmt']);

            info('[WAP] Position from exchange: '.json_encode($positionFromExchange));

            if ((string) $positionQuantity != (string) $totalQuantity) {
                $difference = abs($positionQuantity - $totalQuantity);
                $threshold = abs($positionQuantity) * 0.15;

                info("[WAP] Quantity mismatch — DB: {$totalQuantity}, Exchange: {$positionQuantity}, Diff: {$difference}, Threshold: {$threshold}");

                if ($difference > $threshold) {
                    $resync = true;
                    $error = 'The difference percentage between totalQuantity and positionQuantity exceeds threshold';
                    info('[WAP] Resync needed — '.$error);
                }

                $totalQuantity = $positionQuantity;
            }
        }

        if ($totalQuantity == 0) {
            info('[WAP] Total quantity is 0, returning nulls');

            return [
                'quantity' => null,
                'price' => null,
            ];
        }

        $wapPrice = $totalWeightedPrice / $totalQuantity;
        $profitPercentage = $this->profit_percentage;

        info("[WAP] Raw WAP: {$wapPrice}, Profit %: {$profitPercentage}, Direction: {$this->direction}");

        if ($this->hasAllLimitOrdersFilled() && $positionFromExchange != null) {
            $breakEven = $positionFromExchange['breakEvenPrice'];
            info("[WAP] Using break-even price from exchange: {$breakEven}");

            $wapPrice = $this->adjustForSlippage($breakEven, $this->direction, slippagePercent: 0.03);

            info("[WAP] Adjusted break-even with slippage: {$wapPrice}");
        } else {
            if ($this->direction == 'LONG') {
                $wapPrice = $wapPrice * (1 + $profitPercentage / 100);
                info("[WAP] Added profit % for LONG: {$wapPrice}");
            } elseif ($this->direction == 'SHORT') {
                $wapPrice = $wapPrice * (1 - $profitPercentage / 100);
                info("[WAP] Subtracted profit % for SHORT: {$wapPrice}");
            }
        }

        $formattedQty = api_format_quantity($totalQuantity, $this->exchangeSymbol);
        $formattedPrice = api_format_price($wapPrice, $this->exchangeSymbol);

        info("[WAP] Final Result — Quantity: {$formattedQty}, Price: {$formattedPrice}");

        return [
            'resync' => $resync,
            'error' => $error,
            'quantity' => $formattedQty,
            'price' => $formattedPrice,
        ];
    }
}
