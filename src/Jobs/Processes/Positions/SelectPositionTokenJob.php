<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class SelectPositionTokenJob extends BaseQueuableJob
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
         * Was this token traded on this account as the last symbol to be closed
         * and was it closed in less than 3 minutes, and no more than 5 mins ago?
         * Then select the same token again.
         */
        $exchangeSymbol = $this->tryToGetAfastTradedToken();

        if ($exchangeSymbol) {
            return ['exchangeSymbolId' => $exchangeSymbol->id];
        }
    }

    protected function tryToGetAfastTradedToken()
    {
        // Step 1: Retrieve all ExchangeSymbol models being used in currently opened positions.
        $openPositionExchangeSymbols = ExchangeSymbol::whereIn(
            'id',
            Position::where('account_id', $this->account->id)
            ->opened() // Use the local scope to filter opened positions.
            ->pluck('exchange_symbol_id') // Extract exchange_symbol_id directly.
            ->unique() // Ensure uniqueness.
        )->get(); // Retrieve the collection of ExchangeSymbol models.

        // Step 2: Get all positions closed within the last 5 minutes for this account, with exchange symbols eagerly loaded.
        $recentClosedPositions = Position::where('account_id', $this->account->id)
        ->whereNotNull('closed_at')
        ->where('closed_at', '>=', now()->subMinutes(5))
        ->with('exchangeSymbol') // Eager load the exchangeSymbol relation.
        ->get();

        // Step 3: Filter positions based on trade duration and map to ExchangeSymbol models.
        $fastTradedExchangeSymbols = $recentClosedPositions->filter(function ($position) {
            if (!$position->exchangeSymbol) {
                return false; // Skip positions without a valid exchange symbol.
            }

            $duration = $position->started_at->diffInSeconds($position->closed_at); // Trade duration in seconds.
            return $duration <= 180; // Closed in less than or equal to 3 minutes.
        })->map(function ($position) {
            return $position->exchangeSymbol; // Return the ExchangeSymbol model.
        })->unique(); // Ensure uniqueness of ExchangeSymbols.

        // Step 4: Proceed only if there are fast-traded exchange symbols.
        if ($fastTradedExchangeSymbols->isEmpty()) {
            return null; // No fast-traded exchange symbols, return null.
        }

        // Step 5: Remove exchange symbols already being used in open positions.
        $filteredExchangeSymbols = $fastTradedExchangeSymbols->reject(function ($exchangeSymbol) use ($openPositionExchangeSymbols) {
            return $openPositionExchangeSymbols->contains($exchangeSymbol); // Check if ExchangeSymbol is already used.
        });

        // Step 6: Order the remaining exchange symbols by the shortest trade duration.
        $orderedExchangeSymbols = $filteredExchangeSymbols->sortBy(function ($exchangeSymbol) use ($recentClosedPositions) {
            // Calculate and sort by the duration of the corresponding position.
            $position = $recentClosedPositions->firstWhere('exchange_symbol_id', $exchangeSymbol->id);
            return $position->started_at->diffInSeconds($position->closed_at); // Sort by duration in seconds.
        })->values();

        // Step 7: Return the first ExchangeSymbol model or null if none are available.
        return $orderedExchangeSymbols->first() ?: null;
    }
}
