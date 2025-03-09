<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;
use Nidavellir\Thor\Models\Position;

class DailyReportCommand extends Command
{
    protected $signature = 'mjolnir:daily-report';

    protected $description = 'Reports the daily profit statistics based on wallet balance difference.';

    public function handle()
    {
        // Cache today's start time to avoid redundant function calls.
        $startOfDay = now()->startOfDay();

        // Retrieve all active trader accounts that are eligible to trade.
        $accounts = Account::whereHas('user', fn ($query) => $query->where('is_trader', true))
            ->with(['user', 'quote'])
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            // Fetch the earliest balance snapshot recorded today.
            $startOfDaySnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->where('created_at', '>=', $startOfDay)
                ->oldest('created_at')
                ->first();

            // Fetch the most recent balance snapshot available.
            $currentSnapshot = AccountBalanceHistory::where('account_id', $account->id)
                ->latest('created_at')
                ->first();

            $totalWalletBalance = round($currentSnapshot?->total_wallet_balance ?? 0, 2);
            $startOfDayBalance = round($startOfDaySnapshot?->total_wallet_balance ?? 0, 2);
            $currentDayProfit = round($totalWalletBalance - $startOfDayBalance, 2);
            $uPnL = round($currentSnapshot->total_unrealized_profit, 2);

            // Count the number of trades closed since the start of the day.
            $totalTradesToday = Position::where('account_id', $account->id)
                ->where('status', 'closed')
                ->where('updated_at', '>=', $startOfDay)
                ->count();

            // Send a summary report notification to the account owner.
            $account->user->pushover(
                message: "Wallet Balance: {$totalWalletBalance}, Daily Profit: {$currentDayProfit}, uPnL: {$uPnL}, Trades: {$totalTradesToday}.",
                title: "Account report for {$account->user->name}, ID: {$account->id}.",
                applicationKey: 'nidavellir_cronjobs'
            );
        }

        return Command::SUCCESS;
    }
}
