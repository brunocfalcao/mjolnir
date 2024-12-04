<?php

namespace Nidavellir\Mijolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class AlternativeMeRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [];

    public array $rateLimitHttpCodes = [];

    public int $backoff = 0;
}
