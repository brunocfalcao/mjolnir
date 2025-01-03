<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradeConfiguration;

class AssignTokensToPositionsJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct()
    {
        /**
         * Initialize the account and API system for token assignment.
         * This constructor retrieves the first account and its associated API system.
         * It also sets up the exception handler for the current API system.
         */
        $this->account = Account::first(); // Replace this with appropriate account retrieval logic.
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        /**
         * Fetch all positions in "new" status that belong to the current account and
         * have no exchange symbol assigned. These positions need token assignment.
         */
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->whereNull('exchange_symbol_id')
            ->get();

        /**
         * Fetch all tradeable exchange symbols for the account's quote currency.
         * Include related models like 'symbol' and 'tradeConfiguration' for eager loading.
         */
        $tradeableExchangeSymbols = ExchangeSymbol::tradeable()
            ->where('exchange_symbols.quote_id', $this->account->quote->id)
            ->with('symbol', 'tradeConfiguration')
            ->get();

        /**
         * Fetch already opened exchange symbols for positions belonging to the account.
         * This will help filter out exchange symbols that are already in use.
         */
        $openedExchangeSymbols = collect(
            Position::opened()
                ->whereNotNull('positions.exchange_symbol_id')
                ->where('positions.account_id', $this->account->id)
                ->pluck('positions.exchange_symbol_id')
        );

        /**
         * Filter out exchange symbols that are already assigned to opened positions.
         * Use the collection reject method to remove these symbols from the available list.
         */
        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            return $openedExchangeSymbols->contains($exchangeSymbol->id);
        })->values();

        /**
         * Fetch the direction priority from the default trade configuration.
         * If direction priority is not set, then try to see if we need to
         * follow the BTC indicator from the account configuration.
         */
        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        if ($directionPriority == null) {
            if ($this->account->follow_btc_indicator) {
                $btcExchangeSymbol = ExchangeSymbol::where(
                    'symbol_id',
                    Symbol::firstWhere('token', 'BTC')->id
                )->where('exchange_symbols.quote_id', $this->account->quote->id)
                    ->where('exchange_symbols.is_active', true)
                    ->first();

                // If BTC has a direction concluded, follow it.
                if ($btcExchangeSymbol && $btcExchangeSymbol->direction) {
                    $directionPriority = $btcExchangeSymbol->direction;
                }
            }
        }

        /**
         * Sort the available exchange symbols based on the direction priority (if exists)
         * and the indicator timeframes defined in their trade configurations.
         */
        $orderedExchangeSymbols = $availableExchangeSymbols->sortBy(function ($exchangeSymbol) use ($directionPriority) {
            $indicatorTimeframes = $exchangeSymbol->tradeConfiguration->indicator_timeframes ?? [];
            $indicatorTimeframeIndex = array_search($exchangeSymbol->indicator_timeframe, $indicatorTimeframes);
            $indicatorTimeframeIndex = $indicatorTimeframeIndex !== false ? $indicatorTimeframeIndex : PHP_INT_MAX;

            if ($directionPriority == null) {
                return $indicatorTimeframeIndex; // Sort only by indicator timeframe if no direction priority exists.
            }

            $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

            return [$directionMatch, $indicatorTimeframeIndex];
        })->values();

        /**
         * Iterate over the positions and assign exchange symbols to them.
         * Ensure no duplicate assignments by removing assigned symbols from the collection.
         */
        foreach ($positions as $position) {
            // Try to get a fast-traded token first, if available.
            $fastTradedExchangeSymbol = $this->tryToGetAfastTradedToken();

            if ($fastTradedExchangeSymbol) {
                $this->updatePositionWithExchangeSymbol($position, $fastTradedExchangeSymbol, 'Fast trade exchange symbol.');
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($fastTradedExchangeSymbol) {
                    return $symbol->id == $fastTradedExchangeSymbol->id;
                })->values();

                continue; // Skip to the next position after assigning a fast-traded token.
            }

            // Find an eligible exchange symbol based on allocation rules.
            $eligibleExchangeSymbol = $this->findEligibleSymbol($orderedExchangeSymbols);

            if ($eligibleExchangeSymbol) {
                $this->updatePositionWithExchangeSymbol($position, $eligibleExchangeSymbol);
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($eligibleExchangeSymbol) {
                    return $symbol->id == $eligibleExchangeSymbol->id;
                })->values();
            } else {
                // If no eligible symbol is found, try assigning any remaining fallback symbol.
                if ($orderedExchangeSymbols->isNotEmpty()) {
                    $fallbackSymbol = $orderedExchangeSymbols->shift();
                    $this->updatePositionWithExchangeSymbol($position, $fallbackSymbol, 'Fallback symbol due to no eligible symbol on the right category');
                } else {
                    // If no symbols are left, cancel the position.
                    $position->update(['status' => 'cancelled', 'comments' => 'No ExchangeSymbol available for trading']);
                }
            }
        }

        foreach (Position::opened()->fromAccount($this->account)->get() as $position) {
            $index = 1;
            $blockUuid = (string) Str::uuid();

            CoreJobQueue::create([
                'class' => CreatePositionLifecycleJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }
    }

    protected function findEligibleSymbol($orderedExchangeSymbols)
    {
        /**
         * Calculate the allocation of trades per category based on max concurrent trades.
         * Distribute extra trades evenly across categories.
         */
        $maxConcurrentTrades = $this->account->max_concurrent_trades;
        $categories = Symbol::query()->select('category_canonical')->distinct()->get()->pluck('category_canonical');
        $totalCategories = $categories->count();

        $tradesPerCategory = intdiv($maxConcurrentTrades, $totalCategories);
        $extraTrades = $maxConcurrentTrades % $totalCategories;

        $tradesAllocation = $categories->mapWithKeys(function ($category, $index) use ($tradesPerCategory, $extraTrades) {
            $allocatedTrades = $tradesPerCategory + ($index < $extraTrades ? 1 : 0);

            return [$category => $allocatedTrades];
        });

        /**
         * Fetch already assigned symbols to ensure no duplicates within a category.
         */
        $alreadyAssignedSymbols = Position::opened()
            ->where('positions.account_id', $this->account->id)
            ->pluck('positions.exchange_symbol_id')
            ->toArray();

        foreach ($tradesAllocation as $category => $allowedTrades) {
            $openedTradesCount = Position::opened()
                ->whereHas('exchangeSymbol.symbol', function ($query) use ($category) {
                    $query->where('category_canonical', $category);
                })
                ->count();

            if ($openedTradesCount < $allowedTrades) {
                // Find the first eligible exchange symbol for the category.
                $eligibleExchangeSymbol = $orderedExchangeSymbols
                    ->first(function ($exchangeSymbol) use ($category, $alreadyAssignedSymbols) {
                        return $exchangeSymbol->symbol->category_canonical == $category &&
                            ! in_array($exchangeSymbol->id, $alreadyAssignedSymbols);
                    });

                if ($eligibleExchangeSymbol) {
                    return $eligibleExchangeSymbol; // Return the eligible symbol immediately.
                }
            }
        }

        return null; // Return null if no eligible symbols are found.
    }

    protected function updatePositionWithExchangeSymbol(Position $position, ExchangeSymbol $exchangeSymbol, ?string $comments = null)
    {
        /**
         * Check if the exchange symbol is already selected for another position.
         * If not, update the position with the exchange symbol and comments.
         */
        $exchangeSymbolAlreadySelected = Position::opened()
            ->where('positions.account_id', $this->account->id)
            ->where('positions.exchange_symbol_id', $exchangeSymbol->id)
            ->exists();

        $data = [];

        // Check if we can override the direction.
        if (! $position->direction) {
            $data['direction'] = $exchangeSymbol->direction;
        }

        // Load remaining data.
        $data['exchange_symbol_id'] = $exchangeSymbol->id;
        $data['comments'] = $comments;

        if (! $exchangeSymbolAlreadySelected) {
            $position->update($data);
        } else {
            $position->update([
                'status' => 'cancelled', 'comments' => 'Exchange Symbol position conflict.',
            ]);
        }
    }

    protected function tryToGetAfastTradedToken()
    {
        /**
         * Fetch exchange symbols already in use for opened positions.
         */
        $openPositionExchangeSymbols = ExchangeSymbol::whereIn(
            'id',
            Position::where('positions.account_id', $this->account->id)
                ->opened()
                ->pluck('positions.exchange_symbol_id')
                ->unique()
        )->get();

        /**
         * Fetch recently closed positions within the last 5 minutes.
         */
        $recentClosedPositions = Position::where('positions.account_id', $this->account->id)
            ->whereNotNull('positions.closed_at')
            ->where('positions.closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol.symbol')
            ->get();

        /**
         * Identify fast-traded exchange symbols that were closed within 2 minutes.
         */
        $fastTradedExchangeSymbols = $recentClosedPositions->filter(function ($position) {
            if (! $position->exchangeSymbol || ! $position->exchangeSymbol->is_tradeable) {
                return false;
            }

            $duration = $position->started_at->diffInSeconds($position->closed_at);

            return $duration <= 120; // Fast trade threshold of 2 minutes.
        })->map(function ($position) {
            return $position->exchangeSymbol;
        })->unique();

        if ($fastTradedExchangeSymbols->isEmpty()) {
            return null; // Return null if no fast-traded tokens are found.
        }

        /**
         * Filter out symbols already in use for opened positions.
         */
        $filteredExchangeSymbols = $fastTradedExchangeSymbols->reject(function ($exchangeSymbol) use ($openPositionExchangeSymbols) {
            return $openPositionExchangeSymbols->contains($exchangeSymbol);
        });

        /**
         * Sort symbols by their last trade duration (shortest duration first).
         */
        $orderedExchangeSymbols = $filteredExchangeSymbols->sortBy(function ($exchangeSymbol) use ($recentClosedPositions) {
            $position = $recentClosedPositions->firstWhere('exchange_symbol_id', $exchangeSymbol->id);

            return $position->started_at->diffInSeconds($position->closed_at);
        })->values();

        return $orderedExchangeSymbols->first() ?: null; // Return the first sorted symbol or null.
    }

    public function resolveException(\Throwable $e)
    {
        /**
         * Fail all positions without an assigned exchange symbol in case of an exception.
         */
        Position::where('positions.account_id', $this->account->id)
            ->whereNull('positions.exchange_symbol_id')
            ->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'is_syncing' => false
            ]);
    }
}
