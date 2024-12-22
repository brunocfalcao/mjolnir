<?php

namespace Nidavellir\Mjolnir\Support\RateLimiters;

use Nidavellir\Mjolnir\Abstracts\BaseRateLimiter;

class TaapiRateLimiter extends BaseRateLimiter
{
    public int $rateLimitbackoffSeconds = 5;
}
