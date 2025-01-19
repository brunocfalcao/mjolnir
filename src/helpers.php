<?php

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradingPair;

function adjust_price_with_tick_size(float $price, float $pricePrecision, float $tickSize): float
{
    return remove_trailing_zeros(round(floor($price / $tickSize) * $tickSize, $pricePrecision));
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
    $exchangeSymbol->load('symbol');

    info('[api_format_quantity] '.$exchangeSymbol->symbol->token.' - Entered '.$quantity.' with precision '.$exchangeSymbol->quantity_precision.', got out '.round($quantity, $exchangeSymbol->quantity_precision)).' or with trailing zeros: '.remove_trailing_zeros(round($quantity, $exchangeSymbol->quantity_precision));

    return remove_trailing_zeros(round($quantity, $exchangeSymbol->quantity_precision));
}

function api_format_price($price, ExchangeSymbol $exchangeSymbol)
{
    return remove_trailing_zeros(round(floor($price / $exchangeSymbol->tick_size) * $exchangeSymbol->tick_size, $exchangeSymbol->price_precision));
}

function notional(Position $position)
{
    return remove_trailing_zeros($position->margin * $position->leverage);
}

function remove_trailing_zeros(float $number): float
{
    // Convert the number to a string
    $stringNumber = (string) $number;

    // Iterate backwards to remove trailing zeros
    for ($i = strlen($stringNumber) - 1; $i >= 0; $i--) {
        if ($stringNumber[$i] === '0') {
            $stringNumber = substr($stringNumber, 0, $i);
        } elseif ($stringNumber[$i] === '.') {
            // Remove the decimal point if it's the last character
            $stringNumber = substr($stringNumber, 0, $i);
            break;
        } else {
            break;
        }
    }

    // Convert back to float and return
    return (float) $stringNumber;
}
