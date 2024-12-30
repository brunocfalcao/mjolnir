<?php

use Nidavellir\Thor\Models\TradingPair;

function adjust_price_with_tick_size(float $price, float $pricePrecision, float $tickSize): float
{
    return round(floor($price / $tickSize) * $tickSize, $pricePrecision);
}

function get_trading_pair_for_exchange(string $token, string $exchange)
{
    $tradingPair = TradingPair::firstWhere('token', $token);

    if ($tradingPair) {
        if ($tradingPair->exchange_canonicals != null) {
            if (array_key_exists($exchange, $tradingPair->exchange_canonicals)) {
                return $tradingPair->exchange_canonicals[$exchange];
            }
        }

        return $token;
    }

    return null;
}
