<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Order;
use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\ApiJob;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\QueryOrderJob;
use Nidavellir\Mjolnir\Jobs\GetOpenOrdersJob;

class OrderQueryCommand extends Command
{
    protected $signature = 'excalibur:order-query';

    protected $description = 'Queries an order by either exchange order id or database id';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');

        DB::table('api_jobs')->truncate();

        dd(Order::find(1)->apiQuery());

        return 0;
    }
}
