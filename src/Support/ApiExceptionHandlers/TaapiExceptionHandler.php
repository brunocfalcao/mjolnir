<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;

class TaapiExceptionHandler extends BaseExceptionHandler
{
    public $httpRetryableStatusCodes = [
        504,
        503,
    ];
}
