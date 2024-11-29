<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers;

use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Mjolnir\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;

class TaapiApiDataMapper
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
