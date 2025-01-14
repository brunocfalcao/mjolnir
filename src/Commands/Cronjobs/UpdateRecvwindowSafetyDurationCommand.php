<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;

class UpdateRecvwindowSafetyDurationCommand extends Command
{
    protected $signature = 'mjolnir:update-recvwindow-safety-duration';

    protected $description = 'Updates the API system recvwindow duration (for now only works for Binance)';

    public function handle()
    {
        // Fetch the Binance admin account
        $account = Account::admin('binance');

        if (! $account) {
            $this->error('Binance admin account not found.');

            return 1;
        }

        // Get the server time from Binance API via the admin account
        $response = $account->withApi()->serverTime();
        $serverTime = json_decode($response->getBody(), true)['serverTime']; // Server time in milliseconds

        // Get current system time in milliseconds
        $systemTime = now()->timestamp * 1000; // Convert to milliseconds

        // Calculate the time difference in milliseconds
        $timeDifferenceMs = abs($systemTime - $serverTime);

        // Add 50% safety margin to the time difference
        $recvWindowMargin = $timeDifferenceMs + ($timeDifferenceMs * 0.50); // 50% safety margin

        // Update the `recvwindow_margin` in the `api_systems` table
        ApiSystem::where('canonical', 'binance')->update([
            'recvwindow_margin' => $recvWindowMargin,
            'updated_at' => now(),
        ]);

        return 0;
    }
}
