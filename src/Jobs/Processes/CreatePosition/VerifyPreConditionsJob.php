<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class VerifyPreConditionsJob extends BaseApiableJob
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
        // For now we don't use this method.
        $this->verifyNegativePnLThreshold();

        // Do we have all same-direction positions from the account with all limit orders filled?
        //$this->checkAllPositionsFromSameDirectionFullyFilled();

        return $response->result[$this->account->quote->canonical];
    }

    protected function checkAllPositionsFromSameDirectionFullyFilled()
    {
        $positions = Position::opened()->where('account_id', $this->account->id)->get();

        $longsFilled = 0;
        $shortsFilled = 0;

        foreach ($positions as $position) {
            if ($position->hasAllFilledLimitOrders()) {
                if ($position->direction == 'LONG') {
                    $longsFilled++;
                } else {
                    $shortsFilled++;
                }
            }
        }

        // Do we have as much shorts or longs filled as the one-directon positions from the account?
        if ($positions->where('direction', 'LONG')->count() == $longsFilled || $positions->where('direction', 'SHORT')->count() == $shortsFilled) {
            $this->coreJobQueue->updateToFailed('Cancelling opening positions, because all account same-direction positions have all limit orders filled', true);
        }
    }

    protected function verifyMinimumBalance()
    {
        if ($this->balance[$this->account->quote->canonical]['availableBalance'] < $this->account->minimum_balance) {
            $this->coreJobQueue->updateToFailed('Cancelling Position opening: Account less than the minimum balance', true);
        }
    }

    protected function verifyQuoteBalance()
    {
        if (! array_key_exists($this->account->quote->canonical, $this->balance)) {
            $this->coreJobQueue->updateToFailed('Cancelling Position opening: No quote balance for this account', true);
        }
    }

    protected function verifyNegativePnLThreshold()
    {
        $quoteBalance = $this->balance[$this->account->quote->canonical];

        if (abs($quoteBalance['crossUnPnl']) > $quoteBalance['balance'] * $this->account->negative_pnl_stop_threshold / 100) {
            $this->coreJobQueue->updateToFailed('Cancelling Position opening: Negative PnL exceeds account max negative pnl threshold', true);
        }
    }
}
