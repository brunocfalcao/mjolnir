<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;
use Nidavellir\Thor\Models\Position;

class DailyReportCommand extends Command
{
    protected $signature = 'mjolnir:daily-report';

    protected $description = 'Reports profit statistics based on wallet balance difference from last report.';

    public function handle()
    {
        $accounts = Account::whereHas('user', fn ($query) => $query->where('is_trader', true))
            ->with(['user', 'quote'])
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            $query = AccountBalanceHistory::where('account_id', $account->id);

            // Find starting snapshot
            if ($account->last_report_id) {
                $startSnapshot = (clone $query)
                    ->where('id', '>', $account->last_report_id)
                    ->oldest('id')
                    ->first();
            } else {
                $startSnapshot = (clone $query)->oldest('id')->first();
            }

            if (! $startSnapshot) {
                continue; // Skip if no data
            }

            // Find latest snapshot
            $endSnapshot = (clone $query)->latest('id')->first();

            if (! $endSnapshot || $startSnapshot->id == $endSnapshot->id) {
                continue; // Skip if only one snapshot exists or no progress
            }

            // Compute stats
            $startBalance = round($startSnapshot->total_wallet_balance ?? 0, 2);
            $endBalance = round($endSnapshot->total_wallet_balance ?? 0, 2);
            $profit = round($endBalance - $startBalance, 2);
            $uPnL = round($endSnapshot->total_unrealized_profit ?? 0, 2);

            // Trades closed in range
            $tradesCount = Position::where('account_id', $account->id)
                ->where('status', 'closed')
                ->whereBetween('updated_at', [$startSnapshot->created_at, $endSnapshot->created_at])
                ->count();

            // Format time range for message
            $from = $startSnapshot->created_at->toDateTimeString();
            $to = $endSnapshot->created_at->toDateTimeString();
            $startId = $startSnapshot->id;
            $endId = $endSnapshot->id;

            // Send pushover message about finantial report.
            $account->user->pushover(
                message: "From: {$from} (ID: {$startId})\nTo: {$to} (ID: {$endId})\nProfit: {$profit}\nuPnL: {$uPnL}\nWallet: {$endBalance}\nTrades: {$tradesCount}",
                title: "Account report for {$account->user->name}, ID: {$account->id}.",
                applicationKey: 'nidavellir_cronjobs'
            );

            // Update last_report_id
            $account->update([
                'last_report_id' => $endSnapshot->id,
            ]);
        }

        return Command::SUCCESS;
    }
}
