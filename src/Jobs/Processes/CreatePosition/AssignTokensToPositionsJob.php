<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\TradeConfiguration;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;

class AssignTokensToPositionsJob extends BaseQueuableJob
{
    public Account $account;
    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->whereNull('exchange_symbol_id')
            ->get();

        $fastTradedSymbols = $this->tryToGetFastTradedSymbols();

        info("[AssignTokensToPositionsJob] - Fast traded symbols: " . $fastTradedSymbols->pluck('symbol.token')->join(', '));

        $longCount = $this->account->positions->where('status', 'active')->where('direction', 'LONG')->count();
        $shortCount = $this->account->positions->where('status', 'active')->where('direction', 'SHORT')->count();

        $remainingLongs = ($this->account->should_try_half_positions_direction) ? floor(count($positions) / 2) - $longCount : null;
        $remainingShorts = ($this->account->should_try_half_positions_direction) ? floor(count($positions) / 2) - $shortCount : null;

        $eligibleExchangeSymbols = $this->organizeEligibleSymbols();

        foreach ($eligibleExchangeSymbols as $category => $directions) {
            info("[AssignTokensToPositionsJob] - Category: {$category}");
            foreach ($directions as $direction => $symbols) {
                info("[AssignTokensToPositionsJob] -- Direction: {$direction}");
                foreach ($symbols as $symbol) {
                    info("[AssignTokensToPositionsJob] --- Symbol: {$symbol->symbol->token} ({$symbol->indicator_timeframe})");
                }
            }
        }

        $openedExchangeSymbols = Position::opened()
            ->where('account_id', $this->account->id)
            ->whereNotNull('exchange_symbol_id')
            ->pluck('exchange_symbol_id')
            ->toArray();

        // Remove already opened exchange symbols from eligible symbols
        $eligibleExchangeSymbols = $eligibleExchangeSymbols->map(function ($directions) use ($openedExchangeSymbols) {
            return $directions->map(function ($directionGroup) use ($openedExchangeSymbols) {
                return $directionGroup->reject(function ($symbol) use ($openedExchangeSymbols) {
                    return in_array($symbol->id, $openedExchangeSymbols);
                })->sortBy('indicator_timeframe')->values();
            });
        });

        // Log eligible symbols after removing opened ones
        info("[AssignTokensToPositionsJob] - Eligible symbols after filtering opened positions:");
        foreach ($eligibleExchangeSymbols as $category => $directions) {
            info("[AssignTokensToPositionsJob] - Category: {$category}");
            foreach ($directions as $direction => $symbols) {
                info("[AssignTokensToPositionsJob] -- Direction: {$direction}");
                foreach ($symbols as $symbol) {
                    info("[AssignTokensToPositionsJob] --- Symbol: {$symbol->symbol->token} ({$symbol->indicator_timeframe})");
                }
            }
        }

        // Remove fast-traded symbols already assigned to opened positions
        $fastTradedSymbols = $fastTradedSymbols->reject(function ($symbol) use ($openedExchangeSymbols) {
            return in_array($symbol->id, $openedExchangeSymbols);
        })->values();

        info("[AssignTokensToPositionsJob] - Fast traded symbols after filtering opened positions: " . $fastTradedSymbols->pluck('symbol.token')->join(', '));

        // Initialize category selection tracking to ensure dispersion
        $categorySelectionTracker = collect();

        foreach ($positions as $position) {
            $preferredDirection = $remainingLongs > $remainingShorts ? 'LONG' : 'SHORT';

            $symbol = $fastTradedSymbols->shift() ?: $this->selectNextSymbol($eligibleExchangeSymbols, $preferredDirection, $categorySelectionTracker);

            if ($symbol) {
                $this->assignSymbolToPosition($position, $symbol);

                // Update eligible symbols to remove selected symbol
                $eligibleExchangeSymbols = $this->removeSelectedSymbol($eligibleExchangeSymbols, $symbol);

                $categorySelectionTracker->push($symbol->symbol->category_canonical);

                if ($symbol->direction === 'LONG') {
                    $remainingLongs--;
                } else {
                    $remainingShorts--;
                }
            } else {
                $position->update(['status' => 'cancelled', 'error_message' => 'No eligible symbol available.']);
            }
        }
    }

    private function tryToGetFastTradedSymbols()
    {
        $recentClosedPositions = Position::where('account_id', $this->account->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol.symbol')
            ->get();

        return $recentClosedPositions->filter(function ($position) {
            if (!$position->exchangeSymbol || !$position->exchangeSymbol->isTradeable()) {
                return false;
            }

            $duration = $position->started_at->diffInSeconds($position->closed_at);

            return $duration <= 180;
        })->map(function ($position) {
            return $position->exchangeSymbol;
        })->unique()->values();
    }

    private function organizeEligibleSymbols()
    {
        $timeframeOrder = TradeConfiguration::default()->first()->indicator_timeframes;

        $eligibleSymbols = ExchangeSymbol::eligible()
        ->where('quote_id', $this->account->quote->id)
        ->with('symbol', 'tradeConfiguration')
        ->get()
        ->groupBy(fn ($symbol) => $symbol->symbol->category_canonical)
        ->map(function ($group) use ($timeframeOrder) {
            return $group->groupBy('direction')->map(function ($directionGroup) use ($timeframeOrder) {
                return $directionGroup->sortBy(function ($symbol) use ($timeframeOrder) {
                    // Sort timeframes according to their position in $timeframeOrder
                    return array_search($symbol->indicator_timeframe, $timeframeOrder);
                })->values();
            });
        });

        return $eligibleSymbols;
    }

    private function selectNextSymbol($eligibleExchangeSymbols, $preferredDirection, $categorySelectionTracker)
    {
        // Prioritize unselected categories for dispersion
        $unselectedCategories = $eligibleExchangeSymbols->keys()->diff($categorySelectionTracker);

        foreach ($unselectedCategories as $category) {
            if (isset($eligibleExchangeSymbols[$category][$preferredDirection]) && $eligibleExchangeSymbols[$category][$preferredDirection]->isNotEmpty()) {
                return $eligibleExchangeSymbols[$category][$preferredDirection]->shift();
            }
        }

        foreach ($unselectedCategories as $category) {
            foreach ($eligibleExchangeSymbols[$category] as $directionGroup) {
                if ($directionGroup->isNotEmpty()) {
                    return $directionGroup->shift();
                }
            }
        }

        // Fallback to all categories if no unselected category is available
        foreach ($eligibleExchangeSymbols as $category => $directions) {
            if (isset($directions[$preferredDirection]) && $directions[$preferredDirection]->isNotEmpty()) {
                return $directions[$preferredDirection]->shift();
            }
        }

        foreach ($eligibleExchangeSymbols as $category => $directions) {
            foreach ($directions as $directionGroup) {
                if ($directionGroup->isNotEmpty()) {
                    return $directionGroup->shift();
                }
            }
        }

        return null;
    }

    private function assignSymbolToPosition(Position $position, ExchangeSymbol $symbol)
    {
        $position->update([
            'exchange_symbol_id' => $symbol->id,
            'direction' => $position->direction ?: $symbol->direction,
            'comments' => 'Assigned by AssignTokensToPositionsJob.',
        ]);

        info("[AssignTokensToPositionsJob] - Assigned {$symbol->symbol->token} ({$symbol->direction}) ({$symbol->symbol->category_canonical}) to position ID {$position->id}");
    }

    private function removeSelectedSymbol($eligibleExchangeSymbols, $selectedSymbol)
    {
        return $eligibleExchangeSymbols->map(function ($directions) use ($selectedSymbol) {
            return $directions->map(function ($directionGroup) use ($selectedSymbol) {
                return $directionGroup->reject(function ($symbol) use ($selectedSymbol) {
                    return $symbol->id === $selectedSymbol->id;
                })->values();
            });
        });
    }
}
