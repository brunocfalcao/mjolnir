<?php

namespace Nidavellir\Mijolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class CoinmarketCapRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [401, 402, 403];

    public array $rateLimitHttpCodes = [429];

    public int $backoff = 60;
}
