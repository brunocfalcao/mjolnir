<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;

class UpdateAccountsBalancesCommand extends Command
{
    protected $signature = 'mjolnir:update-accounts-balances';

    protected $description = 'Updates each account balance with PnL, wallet and margin information';

    public function handle()
    {
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true); // Ensure the user is a trader
        })->with('user')
            ->active()
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            $balance = $account->apiQuery()->result;

            // Update directly the account itself.
            $account->update([
                'total_wallet_balance' => $balance['totalWalletBalance'],
                'total_unrealized_profit' => $balance['totalUnrealizedProfit'],
                'total_maintenance_margin' => $balance['totalMaintMargin'],
                'total_margin_balance' => $balance['totalMarginBalance'],
            ]);

            // Save snapshot in the account balance history.
            AccountBalanceHistory::create([
                'account_id' => $account->id,
                'total_wallet_balance' => $balance['totalWalletBalance'],
                'total_unrealized_profit' => $balance['totalUnrealizedProfit'],
                'total_maintenance_margin' => $balance['totalMaintMargin'],
                'total_margin_balance' => $balance['totalMarginBalance'],
            ]);

            // Compute the margin ratio. If more than 2%, send notification.
            $marginRatio = round($balance['totalMaintMargin'] / $balance['totalMarginBalance'] * 100, 2);

            if ($marginRation > 2) {
                $account->user->pushover(
                    message: "Account ID {$account->id} achieved ".$marginRatio.'% margin ratio',
                    title: 'Margin ratio alert',
                    applicationKey: 'nidavellir_warnings'
                );
            }
        }

        return 0;
    }
}
