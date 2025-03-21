<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Concerns\ApiExceptionHelpers;

class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    /**
     * 400: Bad request.
     * -4046: No need to change the margin type.
     * -2013: Order doesn't exist.
     * -5027: No need to modify the order.
     */
    public $httpIgnorableStatusCodes = [
        400 => [-4046, -2013, -5027],
    ];

    /**
     * 400: Bad request.
     * -1021: Timestamp for this request is outside of the recvWindow.
     *
     * 408: Request Timeout
     * -1007: Timeout waiting for response from backend server. Send status unknown.
     *
     * 503: Service unavailable.
     * Several Binance server errors, like workload exceeded, we just retry.
     */
    public $httpRetryableStatusCodes = [
        503,
        504,
        400 => [-1021],
        408 => [-1007],
    ];
}
