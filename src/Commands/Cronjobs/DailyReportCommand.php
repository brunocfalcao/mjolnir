<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;
use Nidavellir\Thor\Models\Position;

class DailyReportCommand extends Command
{
    protected $signature = 'mjolnir:daily-report';

    protected $description = 'Reports the daily profit statistics with accurate adjustments for negative PnL';

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
            // Fetch the snapshot for the beginning of the current day.
            $startOfDaySnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->orderBy('created_at')
                ->first();

            // Fetch the latest snapshot for the account.
            $currentSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->orderByDesc('created_at')
                ->first();

            // Default values for balances.
            $totalWalletBalance = 0;
            $startOfDayBalance = 0;
            $currentDayProfit = 0;

            if ($currentSnapshot) {
                $totalWalletBalance = round($currentSnapshot->total_wallet_balance, 2);
                $currentUnrealizedPnL = abs(round($currentSnapshot->total_unrealized_profit, 2));
            } else {
                $currentUnrealizedPnL = 0;
            }

            if ($startOfDaySnapshot) {
                $startOfDayBalance = round($startOfDaySnapshot->total_wallet_balance, 2);
                $startOfDayUnrealizedPnL = abs(round($startOfDaySnapshot->total_unrealized_profit, 2));
            } else {
                $startOfDayUnrealizedPnL = 0;
            }

            // Adjust the balances by subtracting the absolute negative PnL.
            $adjustedStartBalance = $startOfDayBalance - $startOfDayUnrealizedPnL;
            $adjustedCurrentBalance = $totalWalletBalance - $currentUnrealizedPnL;

            // Calculate the profit for the current day.
            $currentDayProfit = round($adjustedCurrentBalance - $adjustedStartBalance, 2);

            // Count trades closed today.
            $totalTradesToday = Position::where('account_id', $account->id)
                ->where('status', 'closed')
                ->where('updated_at', '>=', now()->startOfDay())
                ->count();

            $account->load('quote');
            $quote = $account->quote->canonical;

            // Notify all admin users via pushover.
            $account->user->each(function ($user) use ($quote, $totalTradesToday, $account, $totalWalletBalance, $currentDayProfit) {
                $user->pushover(
                    message: "Wallet Balance: {$totalWalletBalance} {$quote}, Daily Profit: {$currentDayProfit} {$quote}, Daily Trades: {$totalTradesToday}",
                    title: "Account report for {$account->user->name}, ID: {$account->id}",
                    applicationKey: 'nidavellir_cronjobs'
                );
            });
        }

        return 0;
    }
}
