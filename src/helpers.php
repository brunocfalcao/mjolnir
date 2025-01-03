<?php

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradingPair;

function adjust_price_with_tick_size(float $price, float $pricePrecision, float $tickSize): float
{
    return round(floor($price / $tickSize) * $tickSize, $pricePrecision);
}

function get_base_token_for_exchange(string $token, string $exchangeCanonical)
{
    $tradingPair = TradingPair::firstWhere('token', $token);

    if ($tradingPair) {
        if ($tradingPair->exchange_canonicals != null) {
            if (array_key_exists($exchangeCanonical, $tradingPair->exchange_canonicals)) {
                return $tradingPair->exchange_canonicals[$exchangeCanonical];
            }
        }

        return $token;
    }

    return null;
}

function get_market_order_amount_divider($totalLimitOrders)
{
    return 2 ** ($totalLimitOrders + 1);
}

function api_format_quantity($quantity, ExchangeSymbol $exchangeSymbol)
{
    return round($quantity, $exchangeSymbol->quantity_precision);
}

function api_format_price($price, ExchangeSymbol $exchangeSymbol)
{
    return round(floor($price / $exchangeSymbol->tick_size) * $exchangeSymbol->tick_size, $exchangeSymbol->price_precision);
}

function notional(Position $position)
{
    return remove_trailing_zeros($position->margin * $position->leverage);
}

function remove_trailing_zeros(float $number): float
{
    return (float) rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');
}
