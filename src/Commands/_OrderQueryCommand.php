<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\GetOpenOrdersJob;
use Nidavellir\Mjolnir\Jobs\QueryOrderJob;
use Nidavellir\Thor\Models\ApiJob;

class _OrderQueryCommand extends Command
{
    protected $signature = 'excalibur:order-query';

    protected $description = 'Queries an order by either exchange order id or database id';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');

        DB::table('api_jobs')->truncate();

        $blockUuid = Str::uuid()->toString(); // Auto-generate block UUID for this batch of jobs

        // Add the GetOpenOrdersJob to queue1
        $getOpenOrdersJob = ApiJob::addJob([
            'class' => GetOpenOrdersJob::class,
            'parameters' => [
                'symbol' => 'BTCUSDT',
            ],
            'index' => 1,
            'queue_name' => 'queue1', // Dispatch to queue1
            'block_uuid' => $blockUuid, // Pass the generated blockUuid
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (1)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (2)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (2)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (2)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (2)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 2 (2)', // Or whatever dynamic value is required
            ],
            'index' => 2,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        $queryOrderJob = ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 3', // Or whatever dynamic value is required
            ],
            'index' => 3,
            'queue_name' => 'queue2', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        // Add the QueryOrderJob to queue2
        ApiJob::addJob([
            'class' => QueryOrderJob::class,
            'parameters' => [
                'order_id' => 'from index 3', // Or whatever dynamic value is required
            ],
            'queue_name' => 'queue1', // Dispatch to queue2
            'block_uuid' => $blockUuid, // Pass the same blockUuid to link them
        ]);

        info('Added QueryOrderJob with ID: '.$queryOrderJob->id.' to queue2');

        // Dispatch all pending jobs
        ApiJob::dispatch();

        return 0;
    }
}
