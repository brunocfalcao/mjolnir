<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Concerns\ApiExceptionHelpers;

class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    // Gracefully ignorable codes.
    public $httpIgnorableStatusCodes = [
        400 => [-4046, -2013],
    ];

    // Gracefully retriable codes.
    public $httpRetryableStatusCodes = [
        503,
        400 => [-1021],
    ];
}
