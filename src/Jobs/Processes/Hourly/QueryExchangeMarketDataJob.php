<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;

class QueryExchangeMarketDataJob extends BaseApiableJob
{
    public int $apiSystemId;

    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystemId = $apiSystemId;
        $this->apiSystem = ApiSystem::find($apiSystemId);

        $canonical = $this->apiSystem->canonical;

        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin($canonical));
        $this->exceptionHandler = BaseExceptionHandler::make($canonical);
    }

    public function computeApiable()
    {
        $apiResponse = $this->apiSystem->apiQueryMarketData();
        $this->coreJobQueue->update(['response' => $apiResponse->result]);

        return $apiResponse->response;
    }
}
