<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;

class TaapiApiExceptionHandler extends BaseApiExceptionHandler
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
