<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradeConfiguration;

class _SelectPositionTokenJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public function __construct(int $positionId)
    {
        // Load the position and related account and API system
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        /**
         * Attempt to reuse a recently fast-traded token.
         * This checks if a token was closed in less than 3 minutes and no more than 5 minutes ago.
         * If a valid token is found, update the position with it and stop further processing.
         */
        $exchangeSymbol = $this->tryToGetAfastTradedToken();

        if ($exchangeSymbol) {
            $this->updatePositionWithExchangeSymbol($exchangeSymbol);

            return;
        }

        // Get all currently opened exchange symbols for the account
        $openedExchangeSymbols = Position::opened()
            ->whereNotNull('exchange_symbol_id')
            ->where('account_id', $this->account->id)
            ->get()
            ->pluck('exchange_symbol_id');

        // Fetch all tradeable exchange symbols for the account's quote
        $tradeableExchangeSymbols = ExchangeSymbol::tradeable()
            ->where('quote_id', $this->account->quote->id)
            ->get();

        // Filter out symbols that are already in use by opened positions
        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            return $openedExchangeSymbols->contains($exchangeSymbol->id);
        })->values();

        /**
         * Determine the direction priority for trades.
         * Priority is determined either by a predefined trade configuration or BTC's direction.
         */
        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        if ($directionPriority == null) {
            // Use BTC's direction if no predefined priority exists
            $btcExchangeSymbol = ExchangeSymbol::where(
                'symbol_id',
                Symbol::firstWhere('token', 'BTC')->id
            )->where('quote_id', $this->account->quote->id)
                ->where('is_active', true)
                ->first();

            if ($btcExchangeSymbol) {
                if ($btcExchangeSymbol->direction) {
                    $directionPriority = $btcExchangeSymbol->direction;
                }
            }
        }

        // Eager load relationships for sorting
        $availableExchangeSymbols = $availableExchangeSymbols->load('symbol', 'tradeConfiguration');

        /**
         * Sort exchange symbols based on direction priority (if exists) and timeframes.
         * If no direction priority is defined, sort only by indicator timeframes.
         */
        $orderedExchangeSymbols = $availableExchangeSymbols
            ->sortBy(function ($exchangeSymbol) use ($directionPriority) {
                $indicatorTimeframes = $exchangeSymbol->tradeConfiguration->indicator_timeframes ?? [];
                $indicatorTimeframeIndex = array_search($exchangeSymbol->indicator_timeframe, $indicatorTimeframes);
                $indicatorTimeframeIndex = $indicatorTimeframeIndex !== false ? $indicatorTimeframeIndex : PHP_INT_MAX;

                if ($directionPriority == null) {
                    return $indicatorTimeframeIndex; // Sort only by timeframe
                }

                // Sort first by direction match, then by timeframe
                $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

                return [$directionMatch, $indicatorTimeframeIndex];
            })
            ->values();

        /**
         * Find the most suitable exchange symbol for the position.
         * This ensures the symbol is eligible based on category allocation and concurrency rules.
         */
        $eligibleExchangeSymbol = $this->findEligibleSymbol($orderedExchangeSymbols);

        if ($eligibleExchangeSymbol) {
            $this->updatePositionWithExchangeSymbol($eligibleExchangeSymbol);

            return;
        }
    }

    protected function findEligibleSymbol($orderedExchangeSymbols)
    {
        // Get the maximum number of concurrent trades allowed for the account
        $maxConcurrentTrades = $this->account->max_concurrent_trades;

        // Retrieve all unique symbol categories
        $categories = Symbol::query()->select('category_canonical')->distinct()->get()->pluck('category_canonical');
        $totalCategories = $categories->count();

        // Allocate trades evenly across categories
        $tradesPerCategory = intdiv($maxConcurrentTrades, $totalCategories);
        $extraTrades = $maxConcurrentTrades % $totalCategories;

        // Map categories to their allowed trade allocations
        $tradesAllocation = $categories->mapWithKeys(function ($category, $index) use ($tradesPerCategory, $extraTrades) {
            $allocatedTrades = $tradesPerCategory + ($index < $extraTrades ? 1 : 0);

            return [$category => $allocatedTrades];
        });

        // Get all exchange symbols currently assigned to open positions
        $alreadyAssignedSymbols = Position::opened()
            ->where('account_id', $this->account->id)
            ->get()
            ->pluck('exchange_symbol_id')
            ->toArray();

        // Iterate over categories to find the first with missing trades
        foreach ($tradesAllocation as $category => $allowedTrades) {
            // Count the number of currently opened trades for this category
            $openedTradesCount = Position::opened()
                ->whereHas('exchangeSymbol.symbol', function ($query) use ($category) {
                    $query->where('category_canonical', $category);
                })
                ->count();

            // If the category has room for more trades
            if ($openedTradesCount < $allowedTrades) {
                // Find the first eligible exchange symbol for the category
                $eligibleExchangeSymbol = $orderedExchangeSymbols
                    ->first(function ($exchangeSymbol) use ($category, $alreadyAssignedSymbols) {
                        return $exchangeSymbol->symbol->category_canonical == $category &&
                            ! in_array($exchangeSymbol->id, $alreadyAssignedSymbols); // Ensure it is not already assigned
                    });

                if ($eligibleExchangeSymbol) {
                    return $eligibleExchangeSymbol;
                }
            }
        }

        // Return null if no eligible symbol is found
        return null;
    }

    protected function updatePositionWithExchangeSymbol(ExchangeSymbol $exchangeSymbol)
    {
        /**
         * Check if the exchange symbol is already assigned to another open position
         * in the account. If so, retry the job gracefully.
         */
        $exchangeSymbolAlreadySelected = Position::opened()
            ->where('account_id', $this->account->id)
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->exists();

        if (! $exchangeSymbolAlreadySelected) {
            // Assign the exchange symbol to the position in a transaction
            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);

            return;
        }

        // Retry the job later if a conflict is detected
        $this->coreJobQueue->updateToRetry(now()->addSeconds(10));
    }

    protected function tryToGetAfastTradedToken()
    {
        // Get all exchange symbols currently in use for open positions
        $openPositionExchangeSymbols = ExchangeSymbol::whereIn(
            'id',
            Position::where('account_id', $this->account->id)
                ->opened()
                ->pluck('exchange_symbol_id')
                ->unique()
        )->get();

        // Get recently closed positions within the past 5 minutes
        $recentClosedPositions = Position::where('account_id', $this->account->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol')
            ->get();

        // Filter for positions that were closed in under 3 minutes
        $fastTradedExchangeSymbols = $recentClosedPositions->filter(function ($position) {
            if (! $position->exchangeSymbol) {
                return false;
            }

            $duration = $position->started_at->diffInSeconds($position->closed_at);

            return $duration <= 180;
        })->map(function ($position) {
            return $position->exchangeSymbol;
        })->unique();

        if ($fastTradedExchangeSymbols->isEmpty()) {
            return null;
        }

        // Reject symbols already in use for open positions
        $filteredExchangeSymbols = $fastTradedExchangeSymbols->reject(function ($exchangeSymbol) use ($openPositionExchangeSymbols) {
            return $openPositionExchangeSymbols->contains($exchangeSymbol);
        });

        // Order symbols by the duration of their last trade (ascending)
        $orderedExchangeSymbols = $filteredExchangeSymbols->sortBy(function ($exchangeSymbol) use ($recentClosedPositions) {
            $position = $recentClosedPositions->firstWhere('exchange_symbol_id', $exchangeSymbol->id);

            return $position->started_at->diffInSeconds($position->closed_at);
        })->values();

        return $orderedExchangeSymbols->first() ?: null;
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->update([
            'status' => 'cancelled',
            'error_message' => $e->getMessage(),
        ]);
    }
}
