<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\AlternativeMeApiExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\BinanceApiExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\CoinmarketCapApiExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\TaapiApiExceptionHandler;

abstract class BaseApiExceptionHandler
{
    /**
     * Factory method to return the appropriate API exception handler instance.
     */
    public static function make(string $apiCanonical)
    {
        return match ($apiCanonical) {
            'binance' => new BinanceApiExceptionHandler,
            'taapi' => new TaapiApiExceptionHandler,
            'alternativeme' => new AlternativeMeApiExceptionHandler,
            'coinmarketcap' => new CoinmarketCapApiExceptionHandler,
            default => throw new \Exception("Unsupported Exception API Handler: {$apiCanonical}")
        };
    }

    // By default, there is no resolving the request exception, unless specified.
    public function resolveRequestException(RequestException $exception)
    {
        return null;
    }

    // By default there are no ignorable exceptions, unless specified.
    public function ignoreRequestException(RequestException $exception)
    {
        return false;
    }
}
