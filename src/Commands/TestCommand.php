<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        // dd(Position::find(1)->calculateWAP());

        dd(Account::find(1)->apiQuery()->result);

        return 0;
    }
}
