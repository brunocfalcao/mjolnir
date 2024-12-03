<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use App\Abstracts\BaseApiExceptionHandler;
use GuzzleHttp\Exception\RequestException;

class CoinmarketCapApiExceptionHandler extends BaseApiExceptionHandler
{
    public function ignoreRequestException(RequestException $exception): bool
    {
        return true;
    }

    public function resolveRequestException(RequestException $exception)
    {
        return null;
    }
}
