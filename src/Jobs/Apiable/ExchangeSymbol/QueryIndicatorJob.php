<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\ExchangeSymbol;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;

class QueryIndicatorJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public function __construct(int $exchangeSymbolId, string $timeframe)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;

        $adminAccount = Account::admin('taapi');
        $this->exchangeSymbol->apiAccount = $adminAccount;
        $this->exchangeSymbol->apiDataMapper = new ApiDataMapperProxy('taapi');

        $this->rateLimiter = RateLimitProxy::make('taapi')->withAccount($adminAccount);
        $this->exceptionHandler = BaseExceptionHandler::make('taapi');
    }

    public function computeApiable()
    {
        var_dump($this->exchangeSymbol->apiAccount);
        $this->exchangeSymbol->apiQueryIndicator($this->timeframe);
    }
}
