<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Collections\EligibleExchangeSymbolsForPosition;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class AssignTokensToPositionsJob extends BaseQueuableJob
{
    private ?string $categoryPointer = null; // Pointer to track the last selected category

    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $positions = Position::where('status', 'new')
            ->where(
                'account_id',
                $this->account->id
            )->get();

        $tokens = [];

        foreach ($positions as $position) {
            // Do we already have an exchange symbol and a direction?
            if ($position->exchange_symbol_id != null && $position->direction != null) {
                // Start the position creation lifecycle.
                CoreJobQueue::create([
                    'class' => CreatePositionLifecycleJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);

                $tokens[] = $position->parsedTradingPair;
            } else {
                $selectedExchangeSymbol = EligibleExchangeSymbolsForPosition::getBestExchangeSymbol($position);

                if ($selectedExchangeSymbol) {
                    $data = [];

                    if (! $position->direction) {
                        $data['direction'] = $selectedExchangeSymbol['direction'];
                    }

                    $data['exchange_symbol_id'] = $selectedExchangeSymbol->id;

                    $position->update($data);

                    // Start the position creation lifecycle.
                    CoreJobQueue::create([
                        'class' => CreatePositionLifecycleJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $position->id,
                        ],
                    ]);

                    $tokens[] = $position->parsedTradingPair;
                } else {
                    // Non available exchange symbols. Fail position.
                    $position->updateToFailed('No exchange symbols available, try again later');
                }
            }
        }

        return $tokens;
    }
}
