<?php

namespace Nidavellir\Mjolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class BinanceRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [418, 403, 401];

    public array $ignorableHttpCodes = ['404'];

    public array $retryableHttpCodes = ['503'];

    public int $rateLimitbackoff = 20;
}
