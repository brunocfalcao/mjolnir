<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\ExchangeSymbol;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Testing stuff';

    public function handle()
    {
        ExchangeSymbol::find(1)->logs()->create([
            'action_canonical' => 'wap-triggered',
            'parameters_array' => ['key' => 1, 'value' => 'order'],
        ]);

        return 0;
    }
}
