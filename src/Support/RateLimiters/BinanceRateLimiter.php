<?php

namespace Nidavellir\Mjolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class BinanceRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [418, 403, 401];

    public array $rateLimitHttpCodes = [429];

    public array $ignorableHttpCodes = ['400'];

    public array $retryableHttpCodes = ['502', '503', '504'];

    public int $rateLimitbackoffSeconds = 20;
}
