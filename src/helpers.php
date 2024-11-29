<?php

/**
 * Returns the price adjusted to the tick size, and price precision.
 * It's used when parsing the right price precision to place orders.
 */
function adjustPriceWithTickSize(float $price, float $pricePrecision, float $tickSize): float
{
    return round(floor($price / $tickSize) * $tickSize, $pricePrecision);
}
