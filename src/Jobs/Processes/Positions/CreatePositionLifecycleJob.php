<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Processes\Positions\UpdateTokenLeverageRatioJob;

class CreatePositionLifecycleJob extends BaseQueuableJob
{
    public Account $account;

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = Account::findOrFail($this->position->account->id);
        $this->exceptionHandler = BaseExceptionHandler::make($this->account->apiSystem->canonical);
    }

    public function compute()
    {
        $blockUuid = (string) Str::uuid();
        $index = 1;

        if (! $this->position->margin) {
            CoreJobQueue::create([
                'class' => SelectPositionMarginJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        if (! $this->position->leverage) {
            CoreJobQueue::create([
                'class' => SelectPositionLeverageJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        CoreJobQueue::create([
            'class' => UpdatePositionMarginTypeToCrossedJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateTokenLeverageRatioJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        return;

        CoreJobQueue::create([
            'class' => UpdateRemainingPositionDataJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => DispatchPositionOrdersJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        return $position;
    }
}
