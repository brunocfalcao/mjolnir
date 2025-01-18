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
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradeConfiguration;

class __AssignTokensToPositionsJob extends BaseQueuableJob
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

        $tradeableExchangeSymbols = ExchangeSymbol::eligible()
            ->where('exchange_symbols.quote_id', $this->account->quote->id)
            ->with('symbol', 'tradeConfiguration')
            ->get();

        $openedExchangeSymbols = collect(
            Position::opened()
                ->whereNotNull('positions.exchange_symbol_id')
                ->where('positions.account_id', $this->account->id)
                ->pluck('positions.exchange_symbol_id')
        );

        $availableExchangeSymbols = $tradeableExchangeSymbols->reject(function ($exchangeSymbol) use ($openedExchangeSymbols) {
            return $openedExchangeSymbols->contains($exchangeSymbol->id);
        })->values();

        $directionPriority = TradeConfiguration::default()->first()->direction_priority;

        if ($directionPriority == null && $this->account->follow_btc_indicator) {
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
                return $indicatorTimeframeIndex;
            }

            $directionMatch = $exchangeSymbol->direction == $directionPriority ? 0 : 1;

            return [$directionMatch, $indicatorTimeframeIndex];
        })->values();

        $activePositions = $this->account->positions->where('status', 'active');

        $longCount = $activePositions->where('direction', 'LONG')->count();
        $shortCount = $activePositions->where('direction', 'SHORT')->count();

        foreach ($positions as $position) {
            $preferredDirection = null;

            if ($this->account->should_try_half_positions_direction) {
                $preferredDirection = $longCount <= $shortCount ? 'LONG' : 'SHORT';
            }

            $fastTradedSymbol = $this->tryToGetAfastTradedToken($orderedExchangeSymbols);

            if ($fastTradedSymbol) {
                info('[AssignTokensToPositionsJob] Fast Traded available symbol: ' . $fastTradedSymbol->symbol->token);
                $this->updatePositionWithExchangeSymbol($position, $fastTradedSymbol, 'Fast trade exchange symbol.');
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($fastTradedSymbol) {
                    return $symbol->id == $fastTradedSymbol->id;
                })->values();

                if ($fastTradedSymbol->direction == 'LONG') {
                    $longCount++;
                } else {
                    $shortCount++;
                }

                continue;
            }

            $eligibleExchangeSymbol = $this->findEligibleSymbolByDirection($orderedExchangeSymbols, $preferredDirection);

            if ($eligibleExchangeSymbol) {
                $this->updatePositionWithExchangeSymbol($position, $eligibleExchangeSymbol);
                $orderedExchangeSymbols = $orderedExchangeSymbols->reject(function ($symbol) use ($eligibleExchangeSymbol) {
                    return $symbol->id == $eligibleExchangeSymbol->id;
                })->values();

                if ($eligibleExchangeSymbol->direction == 'LONG') {
                    $longCount++;
                } else {
                    $shortCount++;
                }

                continue;
            }

            if ($orderedExchangeSymbols->isNotEmpty()) {
                $fallbackSymbol = $orderedExchangeSymbols->shift();
                $this->updatePositionWithExchangeSymbol($position, $fallbackSymbol, 'Fallback symbol due to no eligible symbol on the right category');
            } else {
                $position->update(['status' => 'cancelled', 'error_message' => 'No ExchangeSymbol available for trading']);
            }
        }

        foreach (Position::opened()->fromAccount($this->account)->with('account')->get() as $position) {
            info('[AssignTokensToPositionsJob] Starting Lifecycle for account ID '.$position->account->id.' and position ID '.$position->id.' ('.$position->account->user->name.')');

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

    protected function findEligibleSymbolByDirection($orderedExchangeSymbols, $preferredDirection)
    {
        if ($preferredDirection) {
            $symbol = $orderedExchangeSymbols->first(function ($exchangeSymbol) use ($preferredDirection) {
                return $exchangeSymbol->direction == $preferredDirection;
            });

            if ($symbol) {
                return $symbol;
            }
        }

        return $this->findEligibleSymbol($orderedExchangeSymbols);
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

        return null;
    }

    protected function updatePositionWithExchangeSymbol(Position $position, ExchangeSymbol $exchangeSymbol, ?string $comments = null)
    {
        $exchangeSymbolAlreadySelected = Position::opened()
            ->where('positions.account_id', $this->account->id)
            ->where('positions.exchange_symbol_id', $exchangeSymbol->id)
            ->exists();

        $data = [];

        $data['exchange_symbol_id'] = $exchangeSymbol->id;

        if (! $position->direction) {
            $data['direction'] = $exchangeSymbol->direction;
        }

        $data['comments'] = $comments;

        if (! $exchangeSymbolAlreadySelected) {
            $position->load('account.user');
            info("[AssignTokensToPositionsJob] - Assigning {$exchangeSymbol->symbol->token} ({$exchangeSymbol->direction}) to position ID {$position->id}");
            $position->update($data);
        } else {
            $position->update([
                'status' => 'cancelled', 'comments' => 'Exchange Symbol position conflict.',
            ]);
        }
    }

    protected function tryToGetAfastTradedToken($orderedExchangeSymbols)
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
            if (! $position->exchangeSymbol || ! $position->exchangeSymbol->isTradeable()) {
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

        $filteredExchangeSymbols = $fastTradedExchangeSymbols->reject(function ($exchangeSymbol) use ($openPositionExchangeSymbols, $orderedExchangeSymbols) {
            return $openPositionExchangeSymbols->contains($exchangeSymbol) || ! $orderedExchangeSymbols->contains('id', $exchangeSymbol->id);
        });

        $orderedExchangeSymbols = $filteredExchangeSymbols->sortBy(function ($exchangeSymbol) use ($recentClosedPositions) {
            $position = $recentClosedPositions->firstWhere('exchange_symbol_id', $exchangeSymbol->id);

            return $position->started_at->diffInSeconds($position->closed_at);
        })->values();

        info('Fast traded Symbols: ', $orderedExchangeSymbols->pluck('symbol.token')->toArray());

        return $orderedExchangeSymbols->first() ?: null;
    }
}
