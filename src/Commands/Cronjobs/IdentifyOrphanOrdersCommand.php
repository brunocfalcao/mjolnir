<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\User;

class IdentifyOrphanOrdersCommand extends Command
{
    protected $signature = 'mjolnir:identify-orphan-orders';

    protected $description = 'Identifies orders that are not linked to active positions.';

    public function handle()
    {
        // Retrieve active trading accounts.
        $accounts = Account::whereHas('user', fn ($query) => $query->where('is_trader', true))
            ->with('user')
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            // Fetch all open orders from the account.
            $openOrders = $account->apiQueryOpenOrders()->result;

            // Organize orders by their trading pairs.
            $openedTradingPairs = $this->groupOrdersBySymbol($openOrders);

            // Retrieve active position trading pairs for the account.
            $activePositionTradingPairs = $account->positions()
                ->active()
                ->pluck('parsedTradingPair')
                ->flip(); // Converts values to keys for quick lookup.

            // Identify orphan orders and notify admins.
            foreach ($openedTradingPairs as $openedTradingPair => $openedTradingPairOrders) {
                if (! isset($activePositionTradingPairs[$openedTradingPair])) {
                    $this->notifyAdmins($openedTradingPair);
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Groups orders by their trading pair symbol.
     */
    private function groupOrdersBySymbol(array $orders): array
    {
        $groupedOrders = [];
        foreach ($orders as $order) {
            $groupedOrders[$order['symbol']][] = $order;
        }

        return $groupedOrders;
    }

    /**
     * Sends an orphan order notification to all admin users.
     */
    private function notifyAdmins(string $tradingPair): void
    {
        User::admin()->get()->each(fn ($user) => $user->pushover(
            message: "You have possible orphan orders from token {$tradingPair}, please check ASAP.",
            title: 'Identify possible orphan orders',
            applicationKey: 'nidavellir_warnings'
        ));
    }
}
