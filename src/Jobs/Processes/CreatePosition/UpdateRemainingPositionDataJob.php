<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradeConfiguration;

class UpdateRemainingPositionDataJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $data = [];

        if (! $this->position->total_limit_orders) {
            $data['total_limit_orders'] = TradeConfiguration::default()->first()->total_limit_orders;
        }

        if (! $this->position->profit_percentage) {
            $data['profit_percentage'] = $this->account->profit_percentage;
        }

        if (! $this->position->direction) {
            $data['direction'] = $this->position->exchangeSymbol->direction;
        }

        $data['started_at'] = now();

        $this->position->update($data);
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e);
    }
}
