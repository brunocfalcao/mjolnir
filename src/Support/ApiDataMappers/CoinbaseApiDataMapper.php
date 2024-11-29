<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers;

class BybitApiDataMapper
{
    /**
     * Returns the well formed base symbol with the quote on it.
     * E.g.: AVAXUSDT. On other cases, for other exchanges, it can
     * return AVAX/USDT (Coinbase for instance).
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        return $token.'/'.$quote;
    }
}
