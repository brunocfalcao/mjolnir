<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;
use Nidavellir\Thor\Models\User;

class ReportWalletBalanceCommand extends Command
{
    protected $signature = 'mjolnir:report-wallet-balance';

    protected $description = 'Reports wallet balance for each account, via pushover';

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
            // Fetch the latest snapshot for the account.
            $latestSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->orderByDesc('created_at')
                ->first();

            // Fetch the snapshot from exactly 24 hours ago or the closest before that.
            $previousSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->where('created_at', '<=', now()->subDay())
                ->orderByDesc('created_at')
                ->first();

            if ($latestSnapshot && $previousSnapshot) {
                $totalWalletBalance = $latestSnapshot->total_wallet_balance;
                $previousWalletBalance = $previousSnapshot->total_wallet_balance;

                // Calculate the 24-hour differential.
                $diffWalletBalance = $totalWalletBalance - $previousWalletBalance;

                // Log the information to the console.
                $this->info("Account: {$account->user->name}");
                $this->info("Total Wallet Balance: {$totalWalletBalance}");
                $this->info("24h Change: {$diffWalletBalance}");
                $this->info('-------------------------');

                // Notify all admin users via pushover.
                User::admin()->get()->each(function ($user) use ($account, $totalWalletBalance, $diffWalletBalance) {
                    $user->pushover(
                        message: "Account: {$account->user->name}\nTotal Wallet Balance: {$totalWalletBalance}\n24h Change: {$diffWalletBalance}",
                        title: 'Wallet Balance Report (Last 24h)',
                        applicationKey: 'nidavellir_cronjobs'
                    );
                });
            } else {
                $this->info("Account: {$account->user->name} has insufficient data for 24-hour comparison.");
                $this->info('-------------------------');
            }
        }

        return 0;
    }
}
