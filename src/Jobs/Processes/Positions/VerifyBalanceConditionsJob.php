<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;

class VerifyBalanceConditionsJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public array $balance;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        // Lets start by fetching the balance.
        $response = $this->account->apiQueryBalance();

        $this->balance = $response->result;

        // Does this accout has balance in the current account quote?
        $this->verifyQuoteBalance();

        // Does this account has a minimum balance?
        $this->verifyMinimumBalance();

        // Does the total negative PnL surpasses the account negative PnL threshold?
        $this->verifyNegativePnLThreshold();

        // To we have at least one position with all limit orders filled?
        $this->checkAtLeastOnePositionWithAllLimitOrdersFilled();

        // Add a new Position entry. The observer will trigger the position opening start.
        $position = Position::create([
            'account_id' => $this->account->id,
        ]);

        $blockUuid = (string) Str::uuid();

        /**
        if (! $this->initializeExchangeSymbol()) {
            return;
        }
        if (! $this->calculatePositionAmount()) {
            return;
        }
        if (! $this->definePositionSide()) {
            return;
        }
        if (! $this->calculateLeverage()) {
            return;
        }
        if (! $this->meetsMinimumNotional()) {
            return;
        }
        $this->updateMarginTypeToCrossed();
        $this->updateTokenLeverageRatio();
        $this->updateRemainingPositionData();
        $this->dispatchOrders();
         */
        CoreJobQueue::create([
            'class' => SelectPositionTokenJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => CalculatePositionAmountJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => CalculatePositionLeverageJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 3,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateMarginTypeToCrossedJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 4,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateTokenLeverageRatioJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 4,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => UpdateRemainingPositionDataJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 5,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => DispatchPositionOrdersJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 6,
            'block_uuid' => $blockUuid,
        ]);

        return $response->result[$this->account->quote->canonical];
    }

    protected function verifyMinimumBalance()
    {
        if ($this->balance[$this->account->quote->canonical]['availableBalance'] < $this->account->minimum_balance) {
            throw new \Exception('Cancelling Position opening: Account less than the minimum balance');
        }
    }

    protected function verifyQuoteBalance()
    {
        if (! array_key_exists($this->account->quote->canonical, $this->balance)) {
            throw new \Exception('Cancelling Position opening: No quote balance for this account');
        }
    }

    protected function verifyNegativePnLThreshold()
    {
        $quoteBalance = $this->balance[$this->account->quote->canonical];

        if (abs($quoteBalance['crossUnPnl']) > $quoteBalance['balance'] * $this->account->negative_pnl_stop_threshold / 100) {
            throw new \Exception('Cancelling Position opening: Negative PnL exceeds account max negative pnl threshold');
        }
    }

    protected function checkAtLeastOnePositionWithAllLimitOrdersFilled()
    {
        // Get all positions for the account with the "opened" scope applied.
        $positions = Position::opened()->where('account_id', $this->account->id)->get();

        foreach ($positions as $position) {
            // Do we have zero limit orders to fill?
            if ($position->orders()
                ->where('type', 'LIMIT')
                ->where('status', 'NEW')
                ->count() == 0) {
                throw new \Exception('Cancelling Position opening: At least one open position have all limit orders filled');
            }
        }
    }
}
