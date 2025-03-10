<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class SelectPositionMarginJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public float $balance;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
        $this->exchangeSymbol = $this->position->exchangeSymbol;
    }

    public function computeApiable()
    {
        /**
         * The position margin is the absolute portfolio amount used for the
         * current position. It will needed to be updated the positions.margin.
         *
         * The margin is a simple calculation from the accounts.position_size_percentage
         * versus the account balance that exists.
         */

        // Margin already exists?
        if ($this->position->margin) {
            // info('[SelectPositionMarginJob] - Margin (already existed): '.$this->position->margin);

            return;
        }

        if ($this->account->margin_override) {
            // Update the position margin with the account margin override.
            $this->position->update(['margin' => $this->account->margin_override]);
            // info('[SelectPositionMarginJob] - Margin (overrided by account margin): '.$this->position->margin);

            return;
        }

        // Lets start by fetching the balance.
        $response = $this->account->apiQueryBalance();

        $this->balance = $response->result[$this->account->quote->canonical]['crossWalletBalance'];

        // info('[SelectPositionMarginJob] - Balance: '.$this->balance);
        // info('[SelectPositionMarginJob] - Max balance percentage: '.$this->account->max_balance_percentage);
        // info('[SelectPositionMarginJob] - Position size percentage: '.$this->account->position_size_percentage);

        // The available balance will then be sliced for this account size percentage.

        if ($this->position->direction == 'LONG') {
            $sizePercentage = $this->account->position_size_percentage_long;
        } else {
            $sizePercentage = $this->account->position_size_percentage_short;
        }

        $margin = remove_trailing_zeros(
            round(
                $this->balance *
                ($this->account->max_balance_percentage / 100) *
                $sizePercentage / 100
            )
        );

        if ($this->position->margin) {
            // Update the position margin, and move on.
            $this->position->update(['margin' => $margin]);
        } else {
            // Override?
            if (! $this->position->margin) {
                // Update the position margin, and move on.
                $this->position->update(['margin' => $margin]);
            }
        }

        // info('[SelectPositionMarginJob] - Margin (calculated): '.$this->position->margin);
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e);
    }
}
