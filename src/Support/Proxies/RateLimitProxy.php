<?php

namespace Nidavellir\Mjolnir\Support\Proxies;

use Exception;
use Nidavellir\Mjolnir\Support\RateLimiters\AlternativeMeRateLimiter;
use Nidavellir\Mjolnir\Support\RateLimiters\BinanceRateLimiter;
use Nidavellir\Mjolnir\Support\RateLimiters\CoinmarketCapRateLimiter;
use Nidavellir\Mjolnir\Support\RateLimiters\TaapiRateLimiter;

class RateLimitProxy
{
    /**
     * Factory method to create a rate limiter instance based on the type.
     *
     * @return mixed
     *
     * @throws Exception
     */
    public static function make(string $rateLimitType)
    {
        return match ($rateLimitType) {
            'binance' => new BinanceRateLimiter,
            'taapi' => new TaapiRateLimiter,
            'coinmarketcap' => new CoinmarketCapRateLimiter,
            'alternativeme' => new AlternativeMeRateLimiter,
            default => throw new Exception('Unsupported RateLimiter Class: '.$rateLimitType),
        };
    }
}
