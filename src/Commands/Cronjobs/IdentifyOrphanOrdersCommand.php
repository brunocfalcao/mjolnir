<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;

class IdentifyOrphanOrdersCommand extends Command
{
    protected $signature = 'mjolnir:identify-orphan-orders';

    protected $description = 'Identifies possible orders that are no longer part of active positions, or never were';

    public function handle()
    {
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true); // Ensure the user is a trader
        })->with('user')
            ->active()
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            // Query all open orders from the account
            $openOrders = $account->apiQueryOpenOrders()->result;

            // Group orders by token (symbol) using a separate method
            $openedTradingPairs = $this->groupOrdersBySymbol($openOrders);

            // Create an array of active position trading pairs.
            $activePositionTradingPairs = [];
            foreach ($account->positions()->active()->get() as $activePosition) {
                $activePositionTradingPairs[$activePosition->parsedTradingPair] = 1;
            }

            foreach ($openedTradingPairs as $openedTradingPair => $openedTradingPairOrders) {
                $this->info('Opened Trading Pair:'.$openedTradingPair);

                if (! array_key_exists($openedTradingPair, $activePositionTradingPairs)) {
                    $this->info('Possible orphan orders from '.$openedTradingPair);
                }
            }
        }

        return 0;
    }

    /**
     * Groups orders by their symbol.
     */
    private function groupOrdersBySymbol(array $orders): array
    {
        $groupedOrders = [];
        foreach ($orders as $order) {
            $symbol = $order['symbol'];
            if (! isset($groupedOrders[$symbol])) {
                $groupedOrders[$symbol] = [];
            }
            $groupedOrders[$symbol][] = $order;
        }

        return $groupedOrders;
    }
}
