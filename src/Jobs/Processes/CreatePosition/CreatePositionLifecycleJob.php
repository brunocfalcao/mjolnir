<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

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

        if ($this->position->direction == null || $this->position->exchange_symbol_id == null) {
            $this->failPosition('Position without a direction or without an exchange symbol');
        }

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
            'class' => VerifyOrderNotionalOnMarketOrderJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => DispatchPositionOrdersJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        return $this->position;
    }

    public function failPosition(string $message)
    {
        $this->position
            ->update([
                'status' => 'cancelled',
                'error_message' => $message,
            ]);

        $this->notify($message);
    }

    public function resolveException(\Throwable $e)
    {
        $this->position
            ->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

        $this->notify($e->getMessage());
    }

    public function notify($message)
    {
        User::admin()->get()->each(function ($user) use ($message) {
            $user->pushover(
                message: "[{$this->position->id}] - Position opening failed - {$message}",
                title: 'Position opening error',
                applicationKey: 'nidavellir_errors'
            );
        });
    }
}
