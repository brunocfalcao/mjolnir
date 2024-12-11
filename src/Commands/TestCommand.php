<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\UpsertExchangeSymbolsJob;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\UpsertFearAndGreedIndexJob;

class TestCommand extends Command
{
    protected $signature = 'excalibur:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('core_job_queue')->truncate();
        DB::table('rate_limits')->truncate();

        CoreJobQueue::create([
            'class' => UpsertFearAndGreedIndexJob::class,
            'queue' => 'cronjobs',
        ]);

        CoreJobQueue::dispatch();

        return 0;
    }
}
