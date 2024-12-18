<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Concerns\ApiExceptionHelpers;

class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    public $httpIgnorableStatusCodes = [
        400 => [-4046],
        502 => null, // Null means ignore all responses with this HTTP status
    ];

    public $httpRetryableStatusCodes = [
        523 => null, // Retry all 523 responses
        524 => [-1011, -1012], // Retry only these specific error codes for 524
    ];
}
