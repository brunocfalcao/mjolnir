<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class DailyReportCommand extends Command
{
    protected $signature = 'mjolnir:daily-report';

    protected $description = 'Makes a daily report (finance, trades, etc)';

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
            // Fetch the newest snapshot for the account.
            $newestSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->orderByDesc('created_at')
                ->first();

            // Fetch the oldest snapshot within the last 24 hours or the oldest available entry.
            $oldestSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->where('created_at', '>=', now()->subDay())
                ->orderBy('created_at')
                ->first();

            if (! $oldestSnapshot) {
                // If there's no snapshot within the last 24 hours, fall back to the oldest entry.
                $oldestSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                    ->orderBy('created_at')
                    ->first();
            }

            // Default values for balances.
            $totalWalletBalance = 0;
            $previousWalletBalance = 0;
            $diffWalletBalance = 0;

            if ($newestSnapshot) {
                $totalWalletBalance = round($newestSnapshot->total_wallet_balance, 2);
            }

            if ($oldestSnapshot) {
                $previousWalletBalance = round($oldestSnapshot->total_wallet_balance, 2);
            }

            // Calculate the 24-hour differential.
            $diffWalletBalance = round($totalWalletBalance - $previousWalletBalance, 2);

            // Log the information to the console.
            /*
            $this->info("User: {$account->user->name}");
            $this->info("Total Wallet Balance: {$totalWalletBalance}");
            $this->info("24h Change: {$diffWalletBalance}");
            $this->info("Total trades: {$totalTrades}");
            $this->info('-------------------------');
            */

            $totalTrades = Position::where('account_id', $account->id)
                ->where('status', 'closed')
                ->count();

            $account->load('quote');
            $quote = $account->quote->canonical;

            // Notify all admin users via pushover.
            User::admin()->get()->each(function ($user) use ($quote, $totalTrades, $account, $totalWalletBalance, $diffWalletBalance) {
                $user->pushover(
                    message: "Total Wallet Balance: {$totalWalletBalance} {$quote}\n 24h Change: {$diffWalletBalance} {$quote}\n Total Trades: {$totalTrades}",
                    title: "Account report for {$account->user->name}, ID: {$account->id}",
                    applicationKey: 'nidavellir_cronjobs'
                );
            });
        }

        return 0;
    }
}
