<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Resolves the Binance recvWindow issue by syncing server time with a 25% safety margin';

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

        // Add 25% safety margin to the time difference
        $recvWindowMargin = $timeDifferenceMs + ($timeDifferenceMs * 0.25); // 25% safety margin

        // Log the calculated values
        $this->info("Binance Server Time: {$serverTime} ms");
        $this->info("System Time: {$systemTime} ms");
        $this->info("Time Difference: {$timeDifferenceMs} ms");
        $this->info("RecvWindow Margin (with 25% safety): {$recvWindowMargin} ms");

        // Update the `recvwindow_margin` in the `api_systems` table
        ApiSystem::where('canonical', 'binance')->update([
            'recvwindow_margin' => $recvWindowMargin,
            'updated_at' => now(),
        ]);

        $this->info('RecvWindow Margin updated successfully for Binance.');

        return 0;
    }
}
