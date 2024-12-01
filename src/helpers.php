<?php

use Nidavellir\Mjolnir\Support\Proxies\ApiProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Thor\Models\Account;

/**
 * Returns the price adjusted to the tick size, and price precision.
 * It's used when parsing the right price precision to place orders.
 */
function adjustPriceWithTickSize(float $price, float $pricePrecision, float $tickSize): float
{
    return round(floor($price / $tickSize) * $tickSize, $pricePrecision);
}

function api_proxy($canonical = 'binance')
{
    return new ApiProxy(
        'binance',
        /**
         * The credentials stored on the exchange connection are always
         * encrypted, so no need to re-encrypt them again.
         */
        new ApiCredentials(
            Account::admin($canonical)->credentials
        )
    );
}
