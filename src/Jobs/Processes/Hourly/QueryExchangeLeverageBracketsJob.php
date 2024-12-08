<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;

class QueryExchangeLeverageBracketsJob extends BaseApiableJob
{
    public int $apiSystemId;

    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystemId = $apiSystemId;
        $this->apiSystem = ApiSystem::find($apiSystemId);

        $canonical = $this->apiSystem->canonical;

        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin($canonical));
        $this->exceptionHandler = BaseApiExceptionHandler::make($canonical);
    }

    public function computeApiable()
    {
        $this->apiSystem->apiAccount = Account::admin($this->apiSystem->canonical);

        $apiResponse = $this->apiSystem->apiQueryLeverageBracketsData();

        $this->coreJobQueue->update(['response' => $apiResponse->result]);
        return $apiResponse->response;
    }
}
