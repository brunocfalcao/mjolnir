<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Order;

class QueryPositionsCommand extends Command
{
    protected $signature = 'debug:query-positions {account_id}';

    protected $description = 'Queries all positions for an account';

    public function handle()
    {
        $accountId = $this->argument('account_id');

        $account = Account::findOrFail($accountId);

        // Returns all positions for this account.
        $response = $account->apiQueryPositions();

        // Dump the order information
        dd($response->result);

        return 0;
    }
}
