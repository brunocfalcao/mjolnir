<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradeConfiguration;

class _AssignTokensToPositionsJob extends BaseQueuableJob
{
    private ?string $categoryPointer = null; // Pointer to track the last selected category

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
        // Obtain all opened positions that need an exchange symbol to be assigned.
        $positions = Position::opened()
            ->where('account_id', $this->account->id)
            ->where(function ($query) {
                $query->whereNull('exchange_symbol_id')
                    ->orWhereNull('direction');
            })
            ->get();

        $fastTradedSymbols = $this->tryToGetFastTradedSymbols();

        // info('[AssignTokensToPositionsJob] - Fast traded symbols: '.$fastTradedSymbols->pluck('symbol.token')->join(', '));

        $longCount = $this->account->positions->where('status', 'active')->where('direction', 'LONG')->count();
        $shortCount = $this->account->positions->where('status', 'active')->where('direction', 'SHORT')->count();

        $remainingLongs = ($this->account->should_try_half_positions_direction) ? floor(count($positions) / 2) - $longCount : null;
        $remainingShorts = ($this->account->should_try_half_positions_direction) ? floor(count($positions) / 2) - $shortCount : null;

        $eligibleExchangeSymbols = $this->organizeEligibleSymbols();

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

        $fastTradedSymbols = $fastTradedSymbols->reject(function ($symbol) use ($openedExchangeSymbols) {
            return in_array($symbol->id, $openedExchangeSymbols);
        })->values();

        // info('[AssignTokensToPositionsJob] - Fast traded symbols after filtering opened positions: '.$fastTradedSymbols->pluck('symbol.token')->join(', '));

        foreach ($positions as $position) {
            $preferredDirection = $remainingLongs > $remainingShorts ? 'LONG' : 'SHORT';

            $symbol = $fastTradedSymbols->shift() ?: $this->selectNextSymbol($eligibleExchangeSymbols, $preferredDirection);

            if ($symbol) {
                $this->assignSymbolToPosition($position, $symbol);

                $eligibleExchangeSymbols = $this->removeSelectedSymbol($eligibleExchangeSymbols, $symbol);

                if ($symbol->direction === 'LONG') {
                    $remainingLongs--;
                } else {
                    $remainingShorts--;
                }
            } else {
                $position->update(['status' => 'cancelled', 'error_message' => 'No eligible symbol available.']);
            }
        }

        foreach (Position::opened()->fromAccount($this->account)->with('account')->get() as $position) {
            // info('Starting Position lifecycle for account ID '.$position->account->id.' and position ID '.$position->id.' ('.$position->account->user->name.')');

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

    private function tryToGetFastTradedSymbols()
    {
        $recentClosedPositions = Position::where('account_id', $this->account->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subMinutes(5))
            ->with('exchangeSymbol.symbol')
            ->get();

        return $recentClosedPositions->filter(function ($position) {
            if (! $position->exchangeSymbol || ! $position->exchangeSymbol->isTradeable()) {
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

    private function selectNextSymbol($eligibleExchangeSymbols, $preferredDirection)
    {
        $categories = $eligibleExchangeSymbols->keys()->toArray();
        $categoryCount = count($categories);

        // Initialize the pointer if it hasn't been set
        if ($this->categoryPointer === null) {
            $this->categoryPointer = $categories[0];
        }

        // Step 1: Find the starting index based on the last selected category
        $startIndex = array_search($this->categoryPointer, $categories);
        $startIndex = ($startIndex + 1) % $categoryCount; // Move to the next category in a round-robin manner

        // Step 2: Try all categories except the last selected one first
        for ($i = 0; $i < $categoryCount; $i++) {
            $currentIndex = ($startIndex + $i) % $categoryCount;
            $currentCategory = $categories[$currentIndex];

            // Check for the preferred direction within the current category
            if (isset($eligibleExchangeSymbols[$currentCategory][$preferredDirection]) &&
                $eligibleExchangeSymbols[$currentCategory][$preferredDirection]->isNotEmpty()) {
                $symbol = $eligibleExchangeSymbols[$currentCategory][$preferredDirection]->shift();
                $this->categoryPointer = $currentCategory; // Update the pointer to this category

                return $symbol;
            }
        }

        // Step 3: If no preferred direction matches, try all directions in the current cycle
        for ($i = 0; $i < $categoryCount; $i++) {
            $currentIndex = ($startIndex + $i) % $categoryCount;
            $currentCategory = $categories[$currentIndex];

            foreach ($eligibleExchangeSymbols[$currentCategory] as $directionGroup) {
                if ($directionGroup->isNotEmpty()) {
                    $symbol = $directionGroup->shift();
                    $this->categoryPointer = $currentCategory; // Update the pointer to this category

                    return $symbol;
                }
            }
        }

        // Step 4: No eligible symbols available
        return null;
    }

    private function assignSymbolToPosition(Position $position, ExchangeSymbol $symbol)
    {
        $position->update([
            'exchange_symbol_id' => $symbol->id,
            'direction' => $position->direction ?: $symbol->direction,
        ]);

        // info("[AssignTokensToPositionsJob] - Assigned {$symbol->symbol->token} ({$symbol->direction}) ({$symbol->symbol->category_canonical}) to position ID {$position->id}");
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
