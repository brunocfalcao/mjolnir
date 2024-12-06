<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\TestJob;
use Nidavellir\Thor\Models\CoreJobQueue;

class TestCommand extends Command
{
    protected $signature = 'excalibur:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('core_job_queue')->truncate();
        DB::table('rate_limits')->truncate();

        $i = 0;

        while ($i < 2) {
            $blockUuid = (string) Str::uuid();

            // 1.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'orderId' => 1,
                    'positionId' => 1,
                ],
                'block_uuid' => $blockUuid,
                'index' => 1,
                'queue' => 'cronjobs',
                'dispatch_after' => now()->addSeconds(3),
            ]);

            // 2.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'orderId' => 2,
                    'positionId' => 2,
                ],
                'block_uuid' => $blockUuid,
                'index' => 2,
                'queue' => 'cronjobs',
            ]);

            // 3.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'positionId' => 3,
                    'orderId' => 3,
                ],
                'block_uuid' => $blockUuid,
                'index' => 2,
                'queue' => 'cronjobs',
            ]);

            // 4.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'positionId' => 4,
                    'orderId' => 4,
                ],
                'block_uuid' => $blockUuid,
                'index' => 3,
                'queue' => 'cronjobs',
            ]);

            // 5.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'positionId' => 5,
                    'orderId' => 5,
                ],
                'block_uuid' => $blockUuid,
                'queue' => 'cronjobs',
            ]);

            $i++;
        }

        $blockUuid = (string) Str::uuid();

        // 1.
        CoreJobQueue::create([
            'class' => TestJob::class,
            'arguments' => [
                'orderId' => 1,
                'positionId' => 1,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
            'queue' => 'cronjobs',
            'dispatch_after' => now()->addSeconds(15),
        ]);

        // 2.
        CoreJobQueue::create([
            'class' => TestJob::class,
            'arguments' => [
                'orderId' => 2,
                'positionId' => 2,
            ],
            'block_uuid' => $blockUuid,
            'index' => 2,
            'queue' => 'cronjobs',
        ]);

        return 0;
    }
}
