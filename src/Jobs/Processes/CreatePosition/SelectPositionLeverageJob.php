<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class SelectPositionLeverageJob extends BaseQueuableJob
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
        // Get the leverage brackets as an array
        $leverageBrackets = $this->position->exchangeSymbol->leverage_brackets;

        // Validate that leverage_brackets is not empty
        if (! is_array($leverageBrackets) || empty($leverageBrackets['brackets'])) {
            throw new \Exception('Invalid leverage brackets format for symbol: '.$this->position->exchangeSymbol->symbol);
        }

        // Extract the max margin ratio from the account (interpreted as max leverage)

        if ($this->position->direction == 'LONG') {
            $maxLeverage = $this->account->max_leverage_long;
        } else {
            $maxLeverage = $this->account->max_leverage_short;
        }

        // Fetch the position's margin
        $margin = $this->position->margin;

        // Default leverage to the minimum if no valid bracket is found
        $finalLeverage = 1;

        // Iterate over the leverage brackets to find the best fit
        foreach ($leverageBrackets['brackets'] as $bracket) {
            $notionalCap = $bracket['notionalCap'];
            $initialLeverage = $bracket['initialLeverage'];

            // Calculate the notional value for the current leverage
            $notionalValue = $margin * $initialLeverage;

            // Check if the notional value fits within the bracket's cap
            if ($notionalValue <= $notionalCap) {
                // Ensure the leverage does not exceed the max leverage of the account
                if ($initialLeverage <= $maxLeverage) {
                    $finalLeverage = $initialLeverage;
                } else {
                    // Adjust to the closest leverage within the account's max leverage
                    $finalLeverage = min($initialLeverage, $maxLeverage);
                }

                // Stop checking after finding a valid match
                break;
            }
        }

        // Update the position's leverage with the calculated value
        $this->position->leverage = $finalLeverage;
        $this->position->save();

        return "Leverage selected for {$this->position->parsedTradingPair}: {$finalLeverage}";
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e);
    }
}
