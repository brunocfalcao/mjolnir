<?php

namespace Nidavellir\Mjolnir\Support\Collections;

use Illuminate\Support\Collection;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradeConfiguration;

class EligibleExchangeSymbolsForPosition
{
    public static function getBestExchangeSymbol(Position $position)
    {
        // Eager load relationships.
        $position->load(['account.quote', 'exchangeSymbol.symbol']);

        // Get all exchange symbols on active positions.
        $exchangeSymbolsInOpenPositions = self::getActiveExchangeSymbols($position);
        $exchangeSymbolsInOpenPositions->load(['symbol', 'quote']);

        // Get all possible exchange symbols.
        $exchangeSymbolsEligible = self::getEligibleExchangeSymbols($position);
        $exchangeSymbolsEligible->load(['symbol', 'quote']);

        // Reject all exchange symbols with a notional lower than the position notional.
        if ($position->order_ratios && $position->notional && $position->leverage) {
            if ($notional != 0) {
                $exchangeSymbolsEligible = $exchangeSymbolsEligible->reject(function ($exchangeSymbol) use ($position) {

                    $totalLimitOrders = count($position->order_ratios);
                    $notionalForMarketOrder = api_format_price(notional($position) / get_market_order_amount_divider($totalLimitOrders), $exchangeSymbol->price_precision);

                    return $exchangeSymbol->min_notional < $notionalForMarketOrder;
                });
            }
        }

        // Remove the exchange symbols used in positions.
        $exchangeSymbolsAvailable = $exchangeSymbolsEligible->diff($exchangeSymbolsInOpenPositions);

        if ($exchangeSymbolsAvailable->isEmpty()) {
            return null;
        }

        // Eaget load relationships.
        $exchangeSymbolsAvailable->load(['symbol', 'quote']);

        // Sort the exchange symbols by the indicator timeframe.
        $tradeConfiguration = TradeConfiguration::default()->first();
        $timeframes = $tradeConfiguration->indicator_timeframes;

        // Reshuffle exchange symbols.
        $exchangeSymbolsAvailable = $exchangeSymbolsAvailable->shuffle();

        // Sort by shortest timeframe.
        $exchangeSymbolsAvailable = $exchangeSymbolsAvailable->sortBy(function ($exchangeSymbol) use ($timeframes) {
            return array_search($exchangeSymbol->indicator_timeframe, $timeframes);
        });

        // Handle categories.
        $eligibleCategories = $exchangeSymbolsEligible->pluck('symbol.category_canonical')->unique();
        $usedCategories = $exchangeSymbolsInOpenPositions->pluck('symbol.category_canonical')->unique();
        $availableCategories = $eligibleCategories->diff($usedCategories);

        // Make a backup.
        $backupExchangeSymbols = $exchangeSymbolsAvailable;

        $exchangeSymbolsAvailable = $exchangeSymbolsAvailable->filter(function ($exchangeSymbol) use ($availableCategories) {
            return $availableCategories->contains($exchangeSymbol->symbol->category_canonical);
        });

        // Rollback if we dont have symbols.
        if ($exchangeSymbolsAvailable->isEmpty()) {
            $exchangeSymbolsAvailable = $backupExchangeSymbols;
        }

        // Select the best exchange symbol based on account preferences and fallback.
        return self::selectBestExchangeSymbol($exchangeSymbolsAvailable, $exchangeSymbolsInOpenPositions, $position);
    }

    protected static function getEligibleExchangeSymbols(Position $position)
    {
        return ExchangeSymbol::with(['symbol', 'quote'])
            ->eligible()
            ->fromQuote($position->account->quote)
            ->get();
    }

    protected static function getActiveExchangeSymbols(Position $position)
    {
        $activeExchangeSymbolsIds = $position->fromAccount($position->account)
            ->opened()
            ->pluck('exchange_symbol_id');

        return ExchangeSymbol::whereIn('id', $activeExchangeSymbolsIds)
            ->with(['symbol', 'quote'])
            ->get();
    }

    protected static function selectBestExchangeSymbol(Collection $exchangeSymbolsAvailable, Collection $exchangeSymbolsInOpenPositions, Position $position)
    {
        $selectedExchangeSymbol = null;

        if ($position->account->direction_priority) {
            $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', $position->account->direction_priority);
        }

        if (! $selectedExchangeSymbol && $position->account->follow_btc_direction) {
            $btcSymbol = Symbol::firstWhere('token', 'BTC');
            $btcExchangeSymbol = ExchangeSymbol::where('symbol_id', $btcSymbol->id)
                ->where('quote_id', $position->account->quote)
                ->first();

            if ($btcExchangeSymbol->direction) {
                $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', $btcExchangeSymbol->direction);
            }
        }

        // This option overrides a fallback.
        if ($position->account->with_half_positions_direction) {
            $longs = $position->account->positions->where('status', 'active')->where('direction', 'LONG')->count();
            $shorts = $position->account->positions->where('status', 'active')->where('direction', 'SHORT')->count();

            //$longs = $exchangeSymbolsInOpenPositions->where('direction', 'LONG')->count();
            //$shorts = $exchangeSymbolsInOpenPositions->where('direction', 'SHORT')->count();

            /**
             * We should get a short or a long, but in case there is space for the half
             * then we get a position on that direction. E.g.:
             *
             * 4 Shorts available, 12 longs available.
             * But, if we have 5 longs open, we can still open one more long.
             */

            $maxHalfPositions = $position->account->max_concurrent_trades / 2;

            info("Total Longs: {$longs}");
            info("Total Shorts: {$shorts}");
            info("MaxHalfPositions: {$maxHalfPositions}");

            if ($longs < $maxHalfPositions) {
                $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', 'LONG');
            }

            if (!$selectedExchangeSymbol) {
                if ($shorts < $maxHalfPositions) {
                    $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', 'SHORT');
                }
            }

            return $selectedExchangeSymbol;
            /*
            if ($longs >= $shorts) {
                return $exchangeSymbolsAvailable->firstWhere('direction', 'SHORT');
            } else {
                return $exchangeSymbolsAvailable->firstWhere('direction', 'LONG');
            }
            */
        }

        // A last fallback. Just get the shortest timeframe exchange symbol.
        return $selectedExchangeSymbol ?? $exchangeSymbolsAvailable->first();
    }
}
