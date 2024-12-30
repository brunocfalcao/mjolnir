<?php

namespace Nidavellir\Mjolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class BinanceRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [401 => [-2015], 418, 403];

    public array $rateLimitHttpCodes = [429];

    public int $rateLimitbackoffSeconds = 5;
}
