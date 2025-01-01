<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
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
        // Initialize the account and API system for token assignment
        $this->account = Account::first(); // Replace this with the correct account retrieval logic
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        /**
         * Get all positions that need token assignment.
         * Positions must be in "new" status and belong to the current account and
         * also the exchange_symbol_id is null.
         */
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->whereNull('exchange_symbol_id')
            ->get();

        // Fetch all available tradeable exchange symbols for the account's quote
        $tradeableExchangeSymbols = ExchangeSymbol::tradeable()
            ->where('exchange_symbols.quote_id', $this->account->quote->id)
            ->with('symbol', 'tradeConfiguration')
            ->get();

        // Log the available tradeable exchange symbols
        //info('Tradeable Exchange Symbols:', $tradeableExchangeSymbols->pluck('id', 'symbol.token')->toArray());

        /**
         * Filter out exchange symbols already assigned to opened positions.
         */
        $openedExchangeSymbols = Position::opened()
            ->whereNotNull('positions.exchange_symbol_id')
            ->where('positions.account_id', $this->account->id)
            ->pluck('positions.exchange_symbol_id');

        $openedExchangeSymbols = collect($openedExchangeSymbols);

        info('Opened Exchange Symbols: '.json_encode($openedExchangeSymbols->toArray()));

        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            $isRemoved = $openedExchangeSymbols->contains($exchangeSymbol->id);
            if ($isRemoved) {
                info('Removing Exchange Symbol:', ['id' => $exchangeSymbol->id, 'token' => $exchangeSymbol->symbol->token]);
            }

            return $isRemoved;
        })->values();

        // Log the available exchange symbols after filtering
        //info('Available Exchange Symbols:', $availableExchangeSymbols->pluck('id', 'symbol.token')->toArray());

        /**
         * Sort exchange symbols by direction priority and indicator timeframes.
         */
        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        if ($directionPriority == null) {
            $btcExchangeSymbol = ExchangeSymbol::where(
                'symbol_id',
                Symbol::firstWhere('token', 'BTC')->id
            )->where('exchange_symbols.quote_id', $this->account->quote->id)
                ->where('exchange_symbols.is_active', true)
                ->first();

            if ($btcExchangeSymbol && $btcExchangeSymbol->direction) {
                $directionPriority = $btcExchangeSymbol->direction;
            }
        }

        $orderedExchangeSymbols = $availableExchangeSymbols->sortBy(function ($exchangeSymbol) use ($directionPriority) {
            $indicatorTimeframes = $exchangeSymbol->tradeConfiguration->indicator_timeframes ?? [];
            $indicatorTimeframeIndex = array_search($exchangeSymbol->indicator_timeframe, $indicatorTimeframes);
            $indicatorTimeframeIndex = $indicatorTimeframeIndex !== false ? $indicatorTimeframeIndex : PHP_INT_MAX;

            if ($directionPriority == null) {
                return $indicatorTimeframeIndex; // Sort only by timeframe
            }

            $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

            return [$directionMatch, $indicatorTimeframeIndex];
        })->values();

        // Log the ordered exchange symbols
        //info('Ordered Exchange Symbols:', $orderedExchangeSymbols->pluck('id', 'symbol.token')->toArray());

        /**
         * Iterate over positions and assign tokens.
         * Remove assigned symbols from the collection to prevent repetition.
         */
        foreach ($positions as $position) {
            // Log the remaining symbols before processing
            //info('Symbols before processing position '.$position->id.':', $orderedExchangeSymbols->pluck('id', 'symbol.token')->toArray());

            info(' ');
            info('-- Processing Position ID '.$position->id);

            $fastTradedExchangeSymbol = $this->tryToGetAfastTradedToken();

            if ($fastTradedExchangeSymbol) {
                //info('Fast Traded Symbol Selected:', ['id' => $fastTradedExchangeSymbol->id, 'token' => $fastTradedExchangeSymbol->symbol->token]);
                $this->updatePositionWithExchangeSymbol($position, $fastTradedExchangeSymbol, 'Fast trade exchange symbol');
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($fastTradedExchangeSymbol) {
                    return $symbol->id == $fastTradedExchangeSymbol->id;
                })->values();

                // Log the remaining symbols after processing fast traded
                //info('Symbols after fast traded for position '.$position->id.':', $orderedExchangeSymbols->pluck('id', 'symbol.token')->toArray());

                continue;
            }

            $eligibleExchangeSymbol = $this->findEligibleSymbol($orderedExchangeSymbols);

            if ($eligibleExchangeSymbol) {
                info('Eligible Symbol Selected:', ['id' => $eligibleExchangeSymbol->id, 'token' => $eligibleExchangeSymbol->symbol->token]);
                $this->updatePositionWithExchangeSymbol($position, $eligibleExchangeSymbol);
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($eligibleExchangeSymbol) {
                    return $symbol->id == $eligibleExchangeSymbol->id;
                })->values();

                // Log the remaining symbols after processing eligible
                info('Symbols after eligible for position '.$position->id.':', $orderedExchangeSymbols->pluck('id', 'symbol.token')->toArray());
            } else {
                // If no symbol is found, select any remaining symbol or cancel the position
                if ($orderedExchangeSymbols->isNotEmpty()) {
                    $fallbackSymbol = $orderedExchangeSymbols->shift();
                    info('Fallback Symbol Selected:', ['id' => $fallbackSymbol->id, 'token' => $fallbackSymbol->symbol->token]);
                    $this->updatePositionWithExchangeSymbol($position, $fallbackSymbol, 'Fallback symbol due to no eligible symbol');
                } else {
                    info('No eligible symbol found. Remaining symbols:', $orderedExchangeSymbols->pluck('id', 'symbol.token')->toArray());
                    $position->update(['status' => 'cancelled', 'comments' => 'No ExchangeSymbol available for trading']);
                }
            }
        }
    }

    protected function findEligibleSymbol($orderedExchangeSymbols)
    {
        $maxConcurrentTrades = $this->account->max_concurrent_trades;
        $categories = Symbol::query()->select('category_canonical')->distinct()->get()->pluck('category_canonical');
        $totalCategories = $categories->count();

        $tradesPerCategory = intdiv($maxConcurrentTrades, $totalCategories);
        $extraTrades = $maxConcurrentTrades % $totalCategories;

        $tradesAllocation = $categories->mapWithKeys(function ($category, $index) use ($tradesPerCategory, $extraTrades) {
            $allocatedTrades = $tradesPerCategory + ($index < $extraTrades ? 1 : 0);

            return [$category => $allocatedTrades];
        });

        $alreadyAssignedSymbols = Position::opened()
            ->where('positions.account_id', $this->account->id)
            ->get()
            ->pluck('positions.exchange_symbol_id')
            ->toArray();

        foreach ($tradesAllocation as $category => $allowedTrades) {
            $openedTradesCount = Position::opened()
                ->whereHas('exchangeSymbol.symbol', function ($query) use ($category) {
                    $query->where('category_canonical', $category);
                })
                ->count();

            if ($openedTradesCount < $allowedTrades) {
                $eligibleExchangeSymbol = $orderedExchangeSymbols
                    ->first(function ($exchangeSymbol) use ($category, $alreadyAssignedSymbols) {
                        return $exchangeSymbol->symbol->category_canonical == $category &&
                            ! in_array($exchangeSymbol->id, $alreadyAssignedSymbols);
                    });

                if ($eligibleExchangeSymbol) {
                    return $eligibleExchangeSymbol;
                }
            }
        }

        /**
         * Next step is to trigger a core job queue graph for each of the positions
         * to then follow their own lifecycle until the orders are placed.
         */
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

        return null;
    }

    protected function updatePositionWithExchangeSymbol(Position $position, ExchangeSymbol $exchangeSymbol, ?string $comments = null)
    {
        $exchangeSymbolAlreadySelected = Position::opened()
            ->where('positions.account_id', $this->account->id)
            ->where('positions.exchange_symbol_id', $exchangeSymbol->id)
            ->exists();

        info('Updating Position ID '.$position->id.' with Exchange Symbol ID '.$exchangeSymbol->id);
        if (! $exchangeSymbolAlreadySelected) {
            info('Update completed. Position completed.');
            $position->update([
                'exchange_symbol_id' => $exchangeSymbol->id,
                'comments' => $comments,
            ]);
        } else {
            info('Cancelling Position ID '.$position->id.' due to symbol conflict.');
            $position->update(['status' => 'cancelled', 'comments' => 'Exchange Symbol position conflict']);
        }
    }

    protected function tryToGetAfastTradedToken()
    {
        $openPositionExchangeSymbols = ExchangeSymbol::whereIn(
            'id',
            Position::where('positions.account_id', $this->account->id)
                ->opened()
                ->pluck('positions.exchange_symbol_id')
                ->unique()
        )->get();

        $recentClosedPositions = Position::where('positions.account_id', $this->account->id)
            ->whereNotNull('positions.closed_at')
            ->where('positions.closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol.symbol')
            ->get();

        $fastTradedExchangeSymbols = $recentClosedPositions->filter(function ($position) {
            if (! $position->exchangeSymbol || ! $position->exchangeSymbol->is_tradeable) {
                return false;
            }

            $duration = $position->started_at->diffInSeconds($position->closed_at);

            return $duration <= 120;
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

        if ($orderedExchangeSymbols->isNotEmpty()) {
            info('Fast traded token selected: '.$orderedExchangeSymbols->first()->symbol->token);
        }

        return $orderedExchangeSymbols->first() ?: null;
    }

    public function resolveException(\Throwable $e)
    {
        info('Exception occurred, cancelling all positions without assigned exchange symbols.');
        Position::where('positions.account_id', $this->account->id)
            ->whereNull('positions.exchange_symbol_id')
            ->update(['status' => 'cancelled', 'comments' => 'No ExchangeSymbol available for trading']);
    }
}
