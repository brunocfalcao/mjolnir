<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;

class UpdateAccountsBalancesCommand extends Command
{
    protected $signature = 'mjolnir:update-accounts-balances';

    protected $description = 'Updates accounts balances for each active account';

    public function handle()
    {
        // Fetch accounts belonging to traders that are active and can trade.
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true);
        })->with('user')
            ->active()
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->info("Updating balance for account ID: {$account->id}");

                // Fetch balance from API
                $balance = $account->apiQuery()->result;

                // Save snapshot in the account balance history
                AccountBalanceHistory::create([
                    'account_id' => $account->id,
                    'total_wallet_balance' => $balance['totalWalletBalance'],
                    'total_unrealized_profit' => $balance['totalUnrealizedProfit'],
                    'total_maintenance_margin' => $balance['totalMaintMargin'],
                    'total_margin_balance' => $balance['totalMarginBalance'],
                ]);

                $this->info("Balance updated for account ID: {$account->id}");
            } catch (\Exception $e) {
                // Log error and continue with the next account
                Log::error("Failed to update balance for account ID: {$account->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("Error updating balance for account ID: {$account->id}. Check logs for details.");
            }
        }

        $this->info('Balance update completed for all accounts.');

        return 0;
    }
}
