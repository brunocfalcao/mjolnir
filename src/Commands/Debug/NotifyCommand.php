<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\UpdatePnLJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class NotifyCommand extends Command
{
    protected $signature = 'debug:notify';

    protected $description = 'Notify testing';

    public function handle()
    {
        notify(
            title:'This is a title'
        );

        return 0;
    }
}
