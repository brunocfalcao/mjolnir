<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

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

        $eligibleExchangeSymbols = $eligibleExchangeSymbols->map(function ($directions) use ($openedExchangeSymbols) {
            return $directions->map(function ($directionGroup) use ($openedExchangeSymbols) {
                return $directionGroup->reject(function ($symbol) use ($openedExchangeSymbols) {
                    return in_array($symbol->id, $openedExchangeSymbols);
                })->values();
            });
        });

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

        $fastTradedSymbols = $fastTradedSymbols->reject(function ($symbol) use ($openedExchangeSymbols) {
            return in_array($symbol->id, $openedExchangeSymbols);
        })->values();

        info("[AssignTokensToPositionsJob] - Fast traded symbols after filtering opened positions: " . $fastTradedSymbols->pluck('symbol.token')->join(', '));

        $lastSelectedCategory = null;

        foreach ($positions as $position) {
            $preferredDirection = $remainingLongs > $remainingShorts ? 'LONG' : 'SHORT';

            $symbol = $fastTradedSymbols->shift() ?: $this->selectNextSymbol($eligibleExchangeSymbols, $preferredDirection, $lastSelectedCategory);

            if ($symbol) {
                $this->assignSymbolToPosition($position, $symbol);

                $eligibleExchangeSymbols = $this->removeSelectedSymbol($eligibleExchangeSymbols, $symbol);

                $lastSelectedCategory = $symbol->symbol->category_canonical;

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
        $timeframeOrdering = TradeConfiguration::default()->first()->indicator_timeframes;

        $eligibleSymbols = ExchangeSymbol::eligible()
            ->where('quote_id', $this->account->quote->id)
            ->with('symbol', 'tradeConfiguration')
            ->get()
            ->groupBy(fn ($symbol) => $symbol->symbol->category_canonical)
            ->map(function ($group) use ($timeframeOrdering) {
                return $group->groupBy('direction')->map(function ($directionGroup) use ($timeframeOrdering) {
                    return $directionGroup->sortBy(function ($symbol) use ($timeframeOrdering) {
                        return array_search($symbol->indicator_timeframe, $timeframeOrdering);
                    })->values();
                });
            });

        return $eligibleSymbols;
    }

    private function selectNextSymbol($eligibleExchangeSymbols, $preferredDirection, &$lastSelectedCategory)
    {
        $categories = $eligibleExchangeSymbols->keys()->toArray();
        $startIndex = $lastSelectedCategory ? array_search($lastSelectedCategory, $categories) + 1 : 0;

        for ($i = 0; $i < count($categories); $i++) {
            $currentIndex = ($startIndex + $i) % count($categories);
            $currentCategory = $categories[$currentIndex];

            if (isset($eligibleExchangeSymbols[$currentCategory][$preferredDirection]) && $eligibleExchangeSymbols[$currentCategory][$preferredDirection]->isNotEmpty()) {
                return $eligibleExchangeSymbols[$currentCategory][$preferredDirection]->shift();
            }
        }

        for ($i = 0; $i < count($categories); $i++) {
            $currentIndex = ($startIndex + $i) % count($categories);
            $currentCategory = $categories[$currentIndex];

            foreach ($eligibleExchangeSymbols[$currentCategory] as $directionGroup) {
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
