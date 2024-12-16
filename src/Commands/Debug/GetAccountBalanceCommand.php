<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;

class GetAccountBalanceCommand extends Command
{
    protected $signature = 'debug:get-account-balance {accountId}';

    protected $description = 'Retrieves the account balance data from the account argument';

    public function handle()
    {
        $account = Account::findOrFail($this->argument('accountId'));
        $apiDataMapper = new ApiDataMapperProxy($account->apiSystem->canonical);

        dd($account->apiQueryBalance());

        return 0;
    }
}
