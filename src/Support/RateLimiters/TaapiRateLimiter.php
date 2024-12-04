<?php

namespace Nidavellir\Mjolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class TaapiRateLimiter extends BaseRateLimiter
{
    public array $forbidHttpCodes = [401, 402, 403];

    public array $rateLimitHttpCodes = [429];

    public int $backoff = 5;
}
