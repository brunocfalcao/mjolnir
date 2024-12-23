<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class DispatchNewAccountPositionJob extends BaseQueuableJob
{
    public Account $account;

    public array $extraData;

    public function __construct(int $accountId, array $extraData = [])
    {
        $this->account = Account::findOrFail($accountId);
        $this->exceptionHandler = BaseExceptionHandler::make($this->account->apiSystem->canonical);
        $this->extraData = $extraData;
    }

    public function compute()
    {
        $data = array_merge($this->extraData, ['account_id' => $this->account->id]);

        $position = Position::create($data);

        /**
         * When we create a new position we will sequence the next jobs on the
         * core job queue, accordingly to the missing data.
         *
         * We will start a new core job queue block uuid.
         */
        $blockUuid = (string) Str::uuid();
        $index = 1;

        if (! $position->exchange_symbol_id) {
            CoreJobQueue::create([
                'class' => SelectPositionTokenJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        if (! $position->margin) {
            CoreJobQueue::create([
                'class' => CalculatePositionMarginJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        if (! $position->leverage) {
            CoreJobQueue::create([
                'class' => CalculatePositionLeverageJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        CoreJobQueue::create([
            'class' => UpdateMarginTypeToCrossedJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateTokenLeverageRatioJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateRemainingPositionDataJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => DispatchPositionOrdersJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        return $position;
    }
}
