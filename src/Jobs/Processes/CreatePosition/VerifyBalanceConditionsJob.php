<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
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
        // Get all positions for the account with the "opened" scope applied, eager loading the 'orders' relationship
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->with('orders') // Eager load 'orders'
            ->get();

        foreach ($positions as $position) {
            $orders = $position->orders;

            // Check if we have orders
            if ($orders->count() > 0 && $orders->where('type', 'LIMIT')->where('status', 'NEW')->count() == 0) {
                throw new \Exception('Position not created due to at least one position on this account that has all limit orders filled');
            }
        }
    }
}
