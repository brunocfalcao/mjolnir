<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\QueryOrderJob;
use Nidavellir\Thor\Models\ApiJob;
use Nidavellir\Thor\Models\Order;

class OrderQueryCommand extends Command
{
    protected $signature = 'excalibur:order-query';

    protected $description = 'Queries an order by either exchange order id or database id';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('api_jobs')->truncate();

        dd(Order::find(1)->apiQuery());
        //$response = api_proxy()->serverTime();

        //$serverTime = json_decode($response->getBody(), true)['serverTime'];
        //$myTime = microtime(true) * 1000;

        dd($serverTime - $myTime);

        $dataMapper = new ApiDataMapperProxy($this->getApiCanonical());
        $properties = $dataMapper->prepareOrderQuery($this);
        $response = $this->position->account->withApi()->orderQuery($properties);

        return $dataMapper->resolveOrderQuery($response);

        $getOpenOrdersJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 1,
            ],
            'queue_name' => 'queue1',
        ]);

        ApiJob::dispatch();

        $this->info('Job dispatched');

        return 0;
    }
}
