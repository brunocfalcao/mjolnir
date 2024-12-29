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

class _SelectPositionTokenJob extends BaseQueuableJob
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
        info('------------  SELECT POSITION TOKEN JOB -----------------');
        /**
         * Check if this token was traded on this account as the last symbol to be closed
         * and was closed in less than 3 minutes, and no more than 5 mins ago.
         * If so, select the same token again.
         */
        $exchangeSymbol = $this->tryToGetAfastTradedToken();

        if ($exchangeSymbol) {
            $this->updatePositionWithExchangeSymbol($exchangeSymbol);

            return;
        }

        // Compute the available exchange symbols for the next trade
        $openedExchangeSymbols = Position::opened()
            ->whereNotNull('exchange_symbol_id')
            ->where('account_id', $this->account->id)
            ->get()
            ->pluck('exchange_symbol_id');

        $tradeableExchangeSymbols = ExchangeSymbol::tradeable()
            ->where('quote_id', $this->account->quote->id)
            ->get();

        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            return $openedExchangeSymbols->contains($exchangeSymbol->id); // Filter out symbols already in use
        })->values();

        /**
         * Determine direction priority: either from trade configuration or BTC direction.
         * The priority is given to the Trade Configuration in case it exists.
         */
        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        if ($directionPriority == null) {
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

        // Eager load the tradeConfiguration and symbol relationships
        $availableExchangeSymbols = $availableExchangeSymbols->load('symbol', 'tradeConfiguration');

        // Order exchange symbols based on priority (if exists) and timeframes
        $orderedExchangeSymbols = $availableExchangeSymbols
            ->sortBy(function ($exchangeSymbol) use ($directionPriority) {
                $indicatorTimeframes = $exchangeSymbol->tradeConfiguration->indicator_timeframes ?? [];
                $indicatorTimeframeIndex = array_search($exchangeSymbol->indicator_timeframe, $indicatorTimeframes);
                $indicatorTimeframeIndex = $indicatorTimeframeIndex !== false ? $indicatorTimeframeIndex : PHP_INT_MAX;

                // If directionPriority is null, ignore direction and sort only by timeframe
                if ($directionPriority == null) {
                    return $indicatorTimeframeIndex;
                }

                // If directionPriority exists, sort first by direction, then by timeframe
                $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

                return [$directionMatch, $indicatorTimeframeIndex];
            })
            ->values();

        // Log ordered exchange symbols
        info('Ordered Exchange Symbols:');
        $orderedExchangeSymbols->each(function ($exchangeSymbol) {
            info("Exchange Symbol ID: {$exchangeSymbol->id}, Token: {$exchangeSymbol->symbol->token}, Category: {$exchangeSymbol->symbol->category_canonical}, Direction: {$exchangeSymbol->direction}, Timeframe: {$exchangeSymbol->indicator_timeframe}");
        });

        // Find the eligible exchange symbol for this position
        $eligibleExchangeSymbol = $this->findEligibleSymbol($orderedExchangeSymbols);

        if ($eligibleExchangeSymbol) {
            $this->updatePositionWithExchangeSymbol($eligibleExchangeSymbol);
        } else {
            info('No eligible exchange symbol found for position ID: '.$this->position->id);
        }
    }

    protected function findEligibleSymbol($orderedExchangeSymbols)
    {
        $maxConcurrentTrades = $this->account->max_concurrent_trades;
        info("Max Concurrent Trades: $maxConcurrentTrades");

        // Retrieve all distinct categories
        $categories = Symbol::query()->select('category_canonical')->distinct()->get()->pluck('category_canonical');
        $totalCategories = $categories->count();
        info("Total Categories: $totalCategories");
        info('Categories: '.$categories->join(', '));

        // Calculate trades allocation per category
        $tradesPerCategory = intdiv($maxConcurrentTrades, $totalCategories);
        $extraTrades = $maxConcurrentTrades % $totalCategories;
        info("Trades Per Category: $tradesPerCategory");
        info("Extra Trades to Distribute: $extraTrades");

        // Assign trade allocation to each category
        $tradesAllocation = $categories->mapWithKeys(function ($category, $index) use ($tradesPerCategory, $extraTrades) {
            $allocatedTrades = $tradesPerCategory + ($index < $extraTrades ? 1 : 0);
            info("Category: $category, Allocated Trades: $allocatedTrades");

            return [$category => $allocatedTrades];
        });

        // Find the first category with missing trades
        foreach ($tradesAllocation as $category => $allowedTrades) {
            $openedTradesCount = Position::opened()
                ->whereHas('exchangeSymbol.symbol', function ($query) use ($category) {
                    $query->where('category_canonical', $category);
                })
                ->count();

            info("Category: $category, Allowed Trades: $allowedTrades, Opened Trades: $openedTradesCount");

            // If there are missing positions in this category
            if ($openedTradesCount < $allowedTrades) {
                info("Category with missing positions found: $category");

                // Find the first exchange symbol in the ordered collection that belongs to this category
                $eligibleExchangeSymbol = $orderedExchangeSymbols
                    ->first(function ($exchangeSymbol) use ($category) {
                        return $exchangeSymbol->symbol->category_canonical == $category;
                    });

                if ($eligibleExchangeSymbol) {
                    info("Eligible Exchange Symbol Found: ID {$eligibleExchangeSymbol->id}, Token: {$eligibleExchangeSymbol->symbol->token}, Direction: {$eligibleExchangeSymbol->direction}, Timeframe: {$eligibleExchangeSymbol->indicator_timeframe}");

                    return $eligibleExchangeSymbol;
                } else {
                    info("No eligible exchange symbol found for category: $category");

                    return null; // Explicitly return null if no symbol is found
                }
            }
        }

        info('No eligible exchange symbol found for any category.');

        return null;
    }

    protected function updatePositionWithExchangeSymbol(ExchangeSymbol $exchangeSymbol)
    {
        /**
         * Check if the exchange symbol is already selected for any open position
         * in the account. If yes, retry the job gracefully.
         */
        $exchangeSymbolAlreadySelected = Position::opened()
            ->where('account_id', $this->account->id)
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->exists();

        if ($exchangeSymbolAlreadySelected) {
            info("Exchange Symbol ID {$exchangeSymbol->id} is already selected for an open position. Retrying...");
            $this->coreJobQueue->updateToRetry(now()->addSeconds(10));

            return;
        }

        // Ensure the update is atomic to avoid race conditions
        DB::transaction(function () use ($exchangeSymbol) {
            $this->position->update(['exchange_symbol_id' => $exchangeSymbol->id]);
            info("Updated position ID {$this->position->id} with Exchange Symbol ID {$exchangeSymbol->id}");
        });
    }

    protected function tryToGetAfastTradedToken()
    {
        $openPositionExchangeSymbols = ExchangeSymbol::whereIn(
            'id',
            Position::where('account_id', $this->account->id)
                ->opened()
                ->pluck('exchange_symbol_id')
                ->unique()
        )->get();

        $recentClosedPositions = Position::where('account_id', $this->account->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol')
            ->get();

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

        $filteredExchangeSymbols = $fastTradedExchangeSymbols->reject(function ($exchangeSymbol) use ($openPositionExchangeSymbols) {
            return $openPositionExchangeSymbols->contains($exchangeSymbol);
        });

        $orderedExchangeSymbols = $filteredExchangeSymbols->sortBy(function ($exchangeSymbol) use ($recentClosedPositions) {
            $position = $recentClosedPositions->firstWhere('exchange_symbol_id', $exchangeSymbol->id);

            return $position->started_at->diffInSeconds($position->closed_at);
        })->values();

        return $orderedExchangeSymbols->first() ?: null;
    }
}
