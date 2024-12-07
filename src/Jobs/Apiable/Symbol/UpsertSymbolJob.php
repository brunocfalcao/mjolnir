<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Symbol;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;

class UpsertSymbolJob extends BaseApiableJob
{
    public int $symbolId;

    public function __construct(int $symbolId)
    {
        $this->symbolId = $symbolId;
        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin('coinmarketcap'));
        $this->exceptionHandler = BaseApiExceptionHandler::make('coinmarketcap');
    }

    public function computeApiable() {}
}
