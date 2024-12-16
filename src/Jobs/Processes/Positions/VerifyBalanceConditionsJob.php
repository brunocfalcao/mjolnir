<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;

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
        $this->exceptionHandler = BaseApiExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        // Lets start by fetching the balance.
        $response = $this->account->apiQueryBalance();

        $this->balance = $response->result;

        // Does this accout has balance in the current account quote?
        $this->verifyQuoteBalance();

        // Does the total negative PnL surpasses the account negative PnL threshold?
        $this->verifyNegativePnLThreshold();

        // To we have at least one position with all limit orders filled?
        $this->checkAtLeastOnePositionWithAllLimitOrdersFilled();

        // Add a new Position entry. The observer will trigger the position opening start.
        Position::create([
            'account_id' => $this->account->id
        ]);

        return $response->result[$this->account->quote->canonical];
    }

    protected function verifyQuoteBalance()
    {
        if (! array_key_exists($this->account->quote->canonical, $this->balance)) {
            // Cancel position opening, since there is no quote balance.
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
