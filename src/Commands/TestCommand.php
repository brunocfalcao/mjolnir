<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\Account;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {

        DB::table('account_balance_history')->truncate();

        DB::table('account_balance_history')->insert([
            ['account_id' => 2, 'total_wallet_balance' => 22110.66340, 'created_at' => '2025-01-14 22:28:07'],
            ['account_id' => 2, 'total_wallet_balance' => 22112.84087, 'created_at' => '2025-01-14 23:06:14'], // Peak
            ['account_id' => 2, 'total_wallet_balance' => 22050.00000, 'created_at' => '2025-01-14 23:30:00'], // Decline
            ['account_id' => 2, 'total_wallet_balance' => 21900.00000, 'created_at' => '2025-01-14 23:45:00'], // Trough
        ]);

        $startDate = now()->startOfDay();
        $endDate = now()->endOfDay();

        dd(Account::find(2)->calculateMaxDrawdownForRange($startDate, $endDate));

        return 0;
    }
}
