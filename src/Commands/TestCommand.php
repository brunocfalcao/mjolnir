<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\TestJob;
use Nidavellir\Thor\Models\CoreJobQueue;

class TestCommand extends Command
{
    protected $signature = 'excalibur:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        DB::table('core_job_queue')->truncate();
        DB::table('api_job_queue')->truncate();

        $i = 0;

        while ($i < 2) {
            $blockUuid = (string) Str::uuid();

            // 1.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'positionId' => 1,
                    'orderId' => 1,
                ],
                'block_uuid' => $blockUuid,
                'index' => 1,
                'queue' => 'cronjobs',
            ]);

            // 2.
            CoreJobQueue::create([
                'class' => TestJob::class,
                'arguments' => [
                    'positionId' => 2,
                    'orderId' => 1,
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
                    'orderId' => 1,
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
                    'orderId' => 1,
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
                    'orderId' => 1,
                ],
                'block_uuid' => $blockUuid,
                'queue' => 'cronjobs',
            ]);

            $i++;
        }

        return 0;
    }
}
