<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\StorePositionIndicators;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class StorePositionIndicatorsLifecycleJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public string $indicatorIds;

    public string $timeframe;

    public function __construct(int $positionId, string $indicatorIds, string $timeframe)
    {
        $this->position = Position::findOrFail($positionId);
        $this->indicatorIds = $indicatorIds;
        $this->timeframe = $timeframe;

        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $blockUuid = (string) Str::uuid();

        CoreJobQueue::create([
            'class' => QueryAndStoreIndicatorsViaIdsJob::class,
            'queue' => 'indicators',

            'arguments' => [
                'positionId' => $this->position->id,
                'indicatorIds' => $this->indicatorIds,
                'timeframe' => $this->timeframe,
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);
    }

    public function resolveException(\Throwable $e) {}
}
