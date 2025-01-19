<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Quote;
use Nidavellir\Thor\Models\Symbol;

class CreateNewPositionsJob extends BaseQueuableJob
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
        /*
        info('[CreateNewPositionsJob] - Creating '.$this->numPositions.' position(s) to '.$this->account->user->name);

        $testToken = 'SOL';
        $testExchangeSymbol = ExchangeSymbol::where('symbol_id', Symbol::firstWhere('token', 'SOL')->id)
            ->where('quote_id', Quote::firstWhere('canonical', 'USDT')->id)
            ->first();

        $testExchangeSymbol->update(['direction' => 'LONG']);

        info('[CreateNewPositionsJob] - TESTING Exchange Symbol: '.$testExchangeSymbol->symbol->token);

        // TESTING!
        $this->extraData = [
            'exchange_symbol_id' => $testExchangeSymbol->id,
            'direction' => $testExchangeSymbol->direction,
        ];
        */

        $data = array_merge($this->extraData, [
            'account_id' => $this->account->id,
        ]);

        $blockUuid = (string) Str::uuid();

        for ($i = 0; $i < $this->numPositions; $i++) {
            $position = Position::create($data);
        }

        CoreJobQueue::create([
            'class' => AssignTokensToPositionsJob::class,
            'queue' => 'positions',
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
    }
}
