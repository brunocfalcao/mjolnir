<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class CreateNewAccountPositionsJob extends BaseQueuableJob
{
    public Account $account;

    public array $extraData;

    public int $numPositions;

    public function __construct(int $accountId, int $numPositions, array $extraData = [])
    {
        $this->account = Account::findOrFail($accountId);
        $this->exceptionHandler = BaseExceptionHandler::make($this->account->apiSystem->canonical);
        $this->extraData = $extraData;
        $this->numPositions = $numPositions;
    }

    public function compute()
    {
        $data = array_merge($this->extraData, ['account_id' => $this->account->id]);

        for ($i = 0; $i < $this->numPositions; $i++) {
            $position = Position::create($data);
        }

        CoreJobQueue::create([
            'class' => AssignTokensToPositionsJob::class,
            'queue' => 'positions',
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);
    }
}
