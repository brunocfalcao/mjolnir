<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;

class DispatchNewAccountPositionJob extends BaseQueuableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->exceptionHandler = BaseExceptionHandler::make($this->account->apiSystem->canonical);
    }

    public function computeApiable()
    {
        $position = Position::create([
            'account_id' => $this->account->id,
        ]);

        return $position;
    }
}
