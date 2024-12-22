<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler;
use Nidavellir\Mjolnir\Support\ApiExceptionHandlers\TaapiExceptionHandler;

abstract class BaseExceptionHandler
{
    // In case of a retry, how much secs should the work servers back off.
    public int $workerServerBackoffSeconds = 5;

    /**
     * Factory method to return the appropriate API exception handler instance.
     */
    public static function make(string $apiCanonical)
    {
        return match ($apiCanonical) {
            'binance' => new BinanceExceptionHandler,
            'taapi' => new TaapiExceptionHandler,
            'alternativeme' => new AlternativeMeExceptionHandler,
            'coinmarketcap' => new CoinmarketCapExceptionHandler,
            default => throw new \Exception("Unsupported Exception API Handler: {$apiCanonical}")
        };
    }

    // In case we should retry the action, and not raise an exception.
    public function retryException(\Exception $exception): bool
    {
        return false;
    }

    // In case we should ignore the request exception, without retrying it.
    public function ignoreException(\Exception $exception): bool
    {
        return false;
    }

    // Last fallback for resolving any exception at the end.
    public function resolveException(\Throwable $e)
    {
        return null;
    }
}
