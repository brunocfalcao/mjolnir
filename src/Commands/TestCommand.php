<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\CancelOpenOrdersJob;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Testing stuff';

    public function handle()
    {
        CoreJobQueue::create([
            'class' => CancelOpenOrdersJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => 2,
            ],
        ]);

        return 0;
    }
}
