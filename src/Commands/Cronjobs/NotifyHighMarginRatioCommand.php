<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\AccountBalanceHistory;

class NotifyHighMarginRatioCommand extends Command
{
    protected $signature = 'mjolnir:notify-high-margin-ratio';

    protected $description = 'If the margin ratio is above a threshold, it will send a notification';

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

            // Compute the margin ratio. If more than X%, send notification.
            $marginRatio = round($balance['totalMaintMargin'] / $balance['totalMarginBalance'] * 100, 2);

            if ($marginRatio > $account->margin_ratio_notification_threshold) {
                $account->user->pushover(
                    message: "Your account have a high margin ratio: ".$marginRatio."%",
                    title: 'Margin ratio alert',
                    applicationKey: 'nidavellir_warnings'
                );
            }
        }

        return 0;
    }
}
