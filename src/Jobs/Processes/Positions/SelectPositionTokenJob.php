<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class SelectPositionTokenJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public CoreJobQueue $previousCoreJobQueue;

    public function __construct()
    {
        $this->previousCoreJobQueue = $this->coreJobQueue->getPrevious()->first();
        $this->position = Position::findOrFail($previousCoreJobQueue->response['position_id']);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute() {}
}
