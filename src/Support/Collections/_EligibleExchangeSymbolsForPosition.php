<?php

namespace Nidavellir\Mjolnir\Support\Collections;

use Illuminate\Support\Collection;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradeConfiguration;

class _EligibleExchangeSymbolsForPosition
{
    public Position $position;

    public Collection $bag;

    public function __construct(Position $position)
    {
        $this->position = $position;
        // Eager load relationships.
        $this->position->load(['account.quote', 'exchangeSymbol.symbol']);

        $this->bag = new Collection;

        $this->createBag();
    }

    private function getBestExchangeSymbol()
    {
        /**
         * 1st -
         *     Obtain the possible tokens given:
         *     - The position notional for the account number of orders.
         *     - The tokens that are not active on other positions.
         *     - The tokens that are eligible (active, upsertable, tradeable).
         *
         * 1st - We try to spread the tokens as much as possible by the different categories.
         * 2nd - On each token category, sort by the shortest to longest timeframe
         *       and each of the timeframe add all the LONGs and SHORTs.
         *
         * Each time a token is added to the bag, we need to remove it from the big bag.
         */

        // Get all exchange symbols on active positions.
        $exchangeSymbolsInOpenPositions = $this->getActiveExchangeSymbols();
        $exchangeSymbolsInOpenPositions->load(['symbol', 'quote']);

        // Get all possible exchange symbols.
        $exchangeSymbolsEligible = $this->getEligibleExchangeSymbols();
        $exchangeSymbolsEligible->load(['symbol', 'quote']);

        /**
         * If we have a margin and leverage, we need to remove tokens
         * that the notional at the MARKET order is less than the opening.
         */
        $notional = notional($this->position);

        // Reject all exchange symbols that have a notional lower than the position notional.
        if ($notional != 0) {
            $exchangeSymbolsEligible = $exchangeSymbolsEligible->reject(function ($exchangeSymbol) use ($notional) {
                return $exchangeSymbol->min_notional < $notional;
            });
        }

        // Remove the exchange symbols used in positions.
        $exchangeSymbolsAvailable = $exchangeSymbolsEligible->diff($exchangeSymbolsInOpenPositions);
        $exchangeSymbolsAvailable->load(['symbol', 'quote']);

        // Do we have available exchange symbols? If not, return null
        if ($exchangeSymbolsAvailable->isEmpty()) {
            return null;
        }

        /**
         * Now we have the exchangeSymbolsAvailable as our possible
         * exchange symbols to be used on this position! Next step
         * is to find what is the best one.
         */

        /**
         * Lets sort the exchange symbols by the indicator timeframe.
         */
        $tradeConfiguration = TradeConfiguration::default()->first();
        $timeframes = $tradeConfiguration->indicator_timeframes;

        $exchangeSymbolsAvailable = $exchangeSymbolsAvailable->sortBy(function ($exchangeSymbol) use ($timeframes) {
            return array_search($exchangeSymbol->indicator_timeframe, $timeframes);
        });

        /*
        $debugData = $exchangeSymbolsAvailable->map(function ($exchangeSymbol) {
            return [
                'token' => $exchangeSymbol->symbol->token ?? null, // Access symbol->token safely
                'timeframe' => $exchangeSymbol->indicator_timeframe,
                'direction' => $exchangeSymbol->direction,
                'category' => $exchangeSymbol->symbol->category_canonical
            ];
        });

        dd($debugData);
        */

        /*
        dd(
            implode(',', $exchangeSymbolsInOpenPositions->pluck('id')->toArray()),
            implode(',', $exchangeSymbolsEligible->pluck('id')->toArray()),
            implode(',', $exchangeSymbolsAvailable->pluck('id')->toArray())
        );
        */

        /**
         * Lets now get the remaining categories that we should select
         * an exchange symbol from. If there are no remaining categories
         * we will pick one randomly.
         */

        // Get all the categories, from eligible symbols.
        $eligibleCategories = collect(
            $exchangeSymbolsEligible->pluck('symbol.category_canonical')
        )
            ->unique()
            ->values();

        // Get all the used categories.
        $usedCategories = collect(
            $exchangeSymbolsInOpenPositions->pluck('symbol.category_canonical')
        )
            ->unique()
            ->values();

        // Diff them.
        $availableCategories = $eligibleCategories->diff($usedCategories);

        // Backup exchangeSymbolsAvailable;
        $exchangeSymbolsAvailableBackup = $exchangeSymbolsAvailable;

        // Filter exchange symbols that belong to the available categories. Check if we have exchange symbols still.
        $exchangeSymbolsAvailable = $exchangeSymbolsAvailable->filter(function ($exchangeSymbol) use ($availableCategories) {
            return $availableCategories->contains($exchangeSymbol->symbol->category_canonical);
        });

        // Restore if now it's an empty collection. Meaning we need to consider all categories still.
        if ($exchangeSymbolsAvailable->isEmpty()) {
            $exchangeSymbolsAvailable = $exchangeSymbolsAvailableBackup;
        }

        /**
         * The next category selection doesn't matter much, since they are
         * all from different categories from the current opened exchange
         * symbols.
         *
         * What will match now is how will we order the tokens bellonging
         * to all those categories, so we can then select the best token.
         *
         * We need to check if the account have a direction_priority or a
         * follow_btc_direction.
         */
        $selectedExchangeSymbol = null;

        // Try to check direction priority.
        if ($this->position->account->direction_priority) {
            $selectedExchangeSymbol =
                $exchangeSymbolsAvailable
                    ->firstWhere('direction', $this->position->account->direction_priority);
        }

        // Try to check BTC direction.
        if (! $selectedExchangeSymbol) {
            if ($this->position->account->follow_btc_direction) {
                $btcSymbol = Symbol::firstWhere('token', 'BTC');
                $btcExchangeSymbol = ExchangeSymbol::where('symbol_id', $btcSymbol->id)
                    ->where('quote_id', $this->position->account->quote)
                    ->first();

                if ($btcExchangeSymbol->direction) {
                    $selectedExchangeSymbol =
                    $exchangeSymbolsAvailable
                        ->firstWhere('direction', $btcExchangeSymbol->direction);
                }
            }
        }

        // Verify if we have with_half_positions_direction.
        if ($this->position->account->with_half_positions_direction) {
            $longs = $exchangeSymbolsInOpenPositions->where('direction', 'LONG')->count();
            $shorts = $exchangeSymbolsInOpenPositions->where('direction', 'SHORT')->count();

            if ($longs >= $shorts) {
                $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', 'SHORT');
            } else {
                $selectedExchangeSymbol = $exchangeSymbolsAvailable->firstWhere('direction', 'LONG');
            }
        }

        // Select the shortest timeframe exchange symbol, fallback.
        if (! $selectedExchangeSymbol) {
            $selectedExchangeSymbol = $exchangeSymbolsAvailable->first();
        }

        return $selectedExchangeSymbol;
    }

    protected function getEligibleExchangeSymbols()
    {
        // Get all exchange symbols that are eligible for the account quote.
        return ExchangeSymbol::with(['symbol', 'quote'])
            ->eligible()
            ->fromQuote($this->position->account->quote)
            ->get();
    }

    protected function getActiveExchangeSymbols()
    {
        // Get all exchange symbol ids from active positions for this account.
        $activeExchangeSymbolsIds =
            $this->position->fromAccount($this->position->account)
                ->opened()
                ->pluck('exchange_symbol_id');

        // Construct the exchange symbols collection and load relationships in bulk.
        return ExchangeSymbol::whereIn('id', $activeExchangeSymbolsIds)
            ->with(['symbol', 'quote']) // Eager load relationships here to save an extra query
            ->get();
    }

    private function getCollectionDebug(Collection $collection)
    {
        $debugData = $collection->map(function ($exchangeSymbol) {
            return [
                'token' => $exchangeSymbol->symbol->token ?? null, // Access symbol->token safely
                'timeframe' => $exchangeSymbol->indicator_timeframe,
                'direction' => $exchangeSymbol->direction,
                'category' => $exchangeSymbol->symbol->category_canonical,
            ];
        });

        return $debugData;
    }
}
