<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradeConfiguration;

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
            $this->updatePositionWithExchangeSymbol($exchangeSymbol);

            return;
        }

        // Compute the available exchange symbols for the next trade.
        $openedExchangeSymbols =
        Position::opened()
            ->whereNotNull('exchange_symbol_id')
            ->where('account_id', $this->account->id)
            ->get()
            ->pluck('exchange_symbol_id'); // Extract the exchange_symbol_id from the opened positions.

        $tradeableExchangeSymbols =
        ExchangeSymbol::tradeable()
            ->where('quote_id', $this->account->quote->id)
            ->get();

        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            return $openedExchangeSymbols->contains($exchangeSymbol->id); // Filter out symbols already in use.
        })->values(); // Reindex the collection.

        /**
         * We need to order the exchange symbols by the best ones to be picked.
         * The logic is:
         * 1. If the direction priority is selected, use that direction to prioritize.
         * or
         * 1. Give priority to the same direction as BTC.
         *
         * 2. Sort by the lowest indicator timeframe
         * 3.
         */
        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        $btcExchangeSymbol = ExchangeSymbol::where(
            'symbol_id',
            Symbol::firstWhere('token', 'BTC')->id
        )->where('quote_id', $this->account->quote->id)
            ->first();

        if (! $directionPriority) {
            $directionPriority = $btcExchangeSymbol->direction;
        }

        // Eager load the tradeConfiguration relationship
        $availableExchangeSymbols = $availableExchangeSymbols->load('tradeConfiguration');

        $orderedExchangeSymbols = $availableExchangeSymbols
        ->sortBy(function ($exchangeSymbol) use ($directionPriority) {
            // First, prioritize by the direction.
            $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

            // Second, sort by the index of the indicator_timeframe within the indicator_timeframes array.
            $indicatorTimeframes = $exchangeSymbol->tradeConfiguration->indicator_timeframes ?? [];

            // Find the index of the indicator_timeframe in the indicator_timeframes array.
            $indicatorTimeframeIndex = array_search($exchangeSymbol->indicator_timeframe, $indicatorTimeframes);

            // If the timeframe is not found in the array, assign a high value to push it to the end.
            $indicatorTimeframeIndex = $indicatorTimeframeIndex !== false ? $indicatorTimeframeIndex : PHP_INT_MAX;

            // Combine direction match and timeframe index for sorting.
            return [$directionMatch, $indicatorTimeframeIndex];
        })
        ->values(); // Reindex the collection.

        /**
         * We now have a perfect $orderedExchangeSymbols sorted by the right
         * order to select the best token. Next step is to know what category
         * are we missing a token.
         */
        $categories = Symbol::query()
        ->select('category_canonical')
        ->distinct()
        ->get()
        ->pluck('category_canonical');

        $eligibleExchangeSymbol = $this->findEligibleSymbol($orderedExchangeSymbols);
    }

    protected function findEligibleSymbol($orderedExchangeSymbols)
    {
        // Step 1: Calculate the trades per category
        $maxConcurrentTrades = $this->account->max_concurrent_trades;
        info("Max Concurrent Trades: $maxConcurrentTrades");

        $categories = Symbol::query()->select('category_canonical')->distinct()->get()->pluck('category_canonical');
        $totalCategories = $categories->count();
        info("Total Categories: $totalCategories");
        info("Categories: " . $categories->join(', '));

        $tradesPerCategory = intdiv($maxConcurrentTrades, $totalCategories); // Rounded down trades per category
        $extraTrades = $maxConcurrentTrades % $totalCategories; // Remaining trades to be distributed
        info("Trades Per Category: $tradesPerCategory");
        info("Extra Trades to Distribute: $extraTrades");

        // Distribute the trades per category
        $tradesAllocation = $categories->mapWithKeys(function ($category, $index) use ($tradesPerCategory, $extraTrades) {
            $allocatedTrades = $tradesPerCategory + ($index < $extraTrades ? 1 : 0);
            info("Category: $category, Allocated Trades: $allocatedTrades");
            return [$category => $allocatedTrades];
        });

        // Step 2: Check for missing trades in each category
        $missingTrades = $tradesAllocation->filter(function ($allowedTrades, $category) {
            $openedTradesCount = Position::opened()
            ->whereHas('exchangeSymbol', function ($query) use ($category) {
                $query->where('category_canonical', $category);
            })
            ->count();

            info("Category: $category, Allowed Trades: $allowedTrades, Opened Trades: $openedTradesCount");

            return $openedTradesCount < $allowedTrades; // Only return categories with missing trades
        });

        info("Missing Trades Categories: " . $missingTrades->keys()->join(', '));

        // Step 3: Find the eligible exchange symbol for the top-priority missing trade
        foreach ($missingTrades as $category => $allowedTrades) {
            $openedTradesCount = Position::opened()
            ->whereHas('exchangeSymbol', function ($query) use ($category) {
                $query->where('category_canonical', $category);
            })
                ->count();

            info("Processing Category: $category");
            info("Allowed Trades: $allowedTrades, Opened Trades: $openedTradesCount");

            if ($openedTradesCount < $allowedTrades) {
                // Find the topmost exchange symbol for this category
                $eligibleExchangeSymbol = $orderedExchangeSymbols
                    ->first(function ($exchangeSymbol) use ($category) {
                        return $exchangeSymbol->symbol->category_canonical === $category;
                    });

                if ($eligibleExchangeSymbol) {
                        info("Eligible Exchange Symbol Found: " . $eligibleExchangeSymbol->symbol->token);
                        return $eligibleExchangeSymbol;
                } else {
                    info("No eligible exchange symbol found for category: $category");
                }
            }
        }

        info("No eligible exchange symbol found for any category.");
        return null;
    }

    protected function updatePositionWithExchangeSymbol(ExchangeSymbol $exchangeSymbol)
    {
        DB::transaction(function () use ($exchangeSymbol) {
            // Update the position with the selected exchange symbol inside the transaction.
            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
        });
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
            if (! $position->exchangeSymbol) {
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
