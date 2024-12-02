<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\QueryOrderJob;
use Nidavellir\Thor\Models\ApiJobQueue;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\JobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class QueryAllOrdersCommand extends Command
{
    protected $signature = 'excalibur:query-orders';

    protected $description = '';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('api_job_queue')->truncate();
        DB::table('core_job_queue')->truncate();

        $coreJob = new CoreJobQueue;
        $coreJob->generateBlockUuid();

        $coreJob->create([
            'class' => QueryOrderJob::class,
            'arguments' => [
                'position' => Position::find(1),
                'order' => Order::find(1),
            ],
            'queue' => 'cronjobs',
        ]);

        /*
        add(
            jobClass: QueryAllOrdersJob::class,
            arguments: [
                'parameters' => [
                    'api_system_id' => 1,
                ],
            ],
            queueName: 'cronjobs'
        );
        */

        /*
        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'FTMUSDT',
                'order_id' => 24199778294,
            ],
            'indexed' => true,
            'job_queue_id' => $jobQueue->id,
        ]);

        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'ATOMUSDT',
                'order_id' => 20691287235,
            ],
            'indexed' => true,
            'job_queue_id' => $jobQueue->id,
        ]);

        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'GALAUSDT',
                'order_id' => 17599952639,
            ],
            'indexed' => true,
            'job_queue_id' => $jobQueue->id,
        ]);

        //JobQueue::dispatch();
        //ApiJobQueue::dispatch();
        */

        return 0;
    }
}
