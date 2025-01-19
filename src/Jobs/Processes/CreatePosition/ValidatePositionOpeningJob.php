<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class ValidatePositionOpeningJob extends BaseQueuableJob
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
        /**
         * Make some last checks:
         * - All orders synced, and with exchange order id's?
         * - Change position status to active.
         */
        if ($this->position->orders->whereNull('exchange_order_id')->isNotEmpty()) {
            throw new \Exception('Position has orders that were not synced correctly.');
        }

        // Update opening price.
        $this->position->update([
            'opening_price' => $this->position->orders->firstWhere('type', 'MARKET')->price,
        ]);

        $this->position->updateToActive();

        $this->position->load('exchangeSymbol.symbol');
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        $this->position->updateToSynced();
    }
}
