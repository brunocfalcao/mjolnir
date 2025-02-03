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
        $this->checkAllPositionsFromSameDirectionFullyFilled();

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

        // Debugging:
        info('Total positions: '.$positions->count());
        info('Total short positions: '.$positions->where('direction', 'SHORT')->count());
        info('Total long positions: '.$positions->where('direction', 'LONG')->count());

        info('Total LONG all limit filled positions: '.$longsFilled);
        info('Total SHORT all limit filled positions: '.$shortsFilled);

        // Do we have as much shorts or longs filled as the one-directon positions from the account?
        if ($positions->where('direction', 'LONG')->count() == $longsFilled || $positions->where('direction', 'SHORT')->count() == $shortsFilled) {
            info('Cancelling new positions because condition is okay');
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

    protected function checkAtLeastOnePositionWithAllLimitOrdersFilled()
    {
        // Get all positions for the account with the "opened" scope applied, eager loading the 'orders' relationship
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->with('orders')
            ->get();

        foreach ($positions as $position) {
            $orders = $position->orders;

            // Check if we have all the limit orders filled, then we cannot open the position.
            if ($orders->count() > 0 && $orders->where('type', 'LIMIT')->where('status', 'NEW')->count() == 0) {
                $this->coreJobQueue->updateToFailed("Position {$position->parsedTradingPair} ID {$position->id} have all LIMIT orders filled -- Stopping dispatch positions process. No new positions were created", true);
            }
        }
    }
}
