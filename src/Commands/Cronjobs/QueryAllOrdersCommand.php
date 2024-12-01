<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\QueryAllOrdersJob;
use Nidavellir\Mjolnir\Jobs\QueryOrderJob;
use Nidavellir\Thor\Models\ApiJobQueue;
use Nidavellir\Thor\Models\JobQueue;

class QueryAllOrdersCommand extends Command
{
    protected $signature = 'excalibur:query-orders';

    protected $description = '';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('job_api_queue')->truncate();
        DB::table('job_block_queue')->truncate();

        $jobQueue = JobQueue::add(
            jobClass: QueryAllOrdersJob::class,
            arguments: [
                'parameters' => [
                    'api_system_id' => $exchange->id,
                    'job_queue_id' => $jobQueue->id,
                ],
            ],
            queueName: 'cronjobs'
        );

        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'FTMUSDT',
                'order_id' => 24199778294,
                'job_queue_id' => $jobQueue->id,
            ],
            'indexed' => true,
        ]);

        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'ATOMUSDT',
                'order_id' => 20691287235,
                'job_queue_id' => $jobQueue->id,
            ],
            'indexed' => true,
        ]);

        $apiJob = ApiJobQueue::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'symbol' => 'GALAUSDT',
                'order_id' => 17599952639,
                'job_queue_id' => $jobQueue->id,
            ],
            'indexed' => true,
        ]);

        //JobQueue::dispatch();
        //ApiJobQueue::dispatch();

        return 0;
    }
}
