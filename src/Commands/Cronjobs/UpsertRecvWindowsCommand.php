<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Cronjobs\UpsertRecvWindowsJob;
use Nidavellir\Thor\Models\ApiJob;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\JobQueue;

class UpsertRecvWindowsCommand extends Command
{
    protected $signature = 'excalibur:upsert-recvwindows';

    protected $description = 'Updates the recvwindow for all api systems (exchanges)';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('job_api_queue')->truncate();
        DB::table('job_block_queue')->truncate();

        $blockUuid = (string) Str::uuid();

        ApiSystem::allExchanges()->each(function ($exchange, $index) use ($blockUuid) {
            $jobQueue = JobQueue::add(
                jobClass: UpsertRecvWindowsJob::class,
                arguments: [
                    'parameters' => [
                        'api_system_id' => $exchange->id,
                        'job_queue_block_uuid' => $blockUuid,
                    ],
                ],
                block: $block,
                queueName: 'cronjobs'
            );
        });
        /*
        foreach (ApiSystem::allExchanges() as $exchange) {
            $getOpenOrdersJob = ApiJob::addJob([
                'class' => UpsertRecvWindowsJob::class,
                'parameters' => [
                    'api_system_id' => $exchange->id,
                ],
                'queue_name' => 'cronjobs',
            ]);
        }
        */

        return 0;
    }
}
