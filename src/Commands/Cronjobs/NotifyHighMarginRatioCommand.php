<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;

class NotifyHighMarginRatioCommand extends Command
{
    protected $signature = 'mjolnir:notify-high-margin-ratio';

    protected $description = 'Send a notification if the margin ratio exceeds the defined threshold.';

    public function handle()
    {
        // Retrieve active trading accounts.
        $accounts = Account::whereHas('user', fn ($query) => $query->where('is_trader', true))
            ->with('user')
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            $balance = $account->apiQuery()->result;

            // Ensure necessary balance keys exist before computing margin ratio.
            if (! isset($balance['totalMaintMargin'], $balance['totalMarginBalance']) || $balance['totalMarginBalance'] == 0) {
                continue;
            }

            // Compute the margin ratio percentage.
            $marginRatio = round(($balance['totalMaintMargin'] / $balance['totalMarginBalance']) * 100, 2);

            // Send notification if the margin ratio exceeds the account's threshold.
            if ($marginRatio > $account->margin_ratio_notification_threshold) {
                $this->sendMarginAlert($account, $marginRatio);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Sends a margin ratio alert notification to the user.
     */
    private function sendMarginAlert(Account $account, float $marginRatio): void
    {
        $account->user->pushover(
            message: "Your account has a high margin ratio: {$marginRatio}%.",
            title: 'Margin Ratio Alert',
            applicationKey: 'nidavellir_warnings'
        );
    }
}
