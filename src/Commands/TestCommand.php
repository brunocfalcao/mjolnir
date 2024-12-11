<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\UpsertExchangeSymbolsJob;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;

class TestCommand extends Command
{
    protected $signature = 'excalibur:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        //DB::table('core_job_queue')->truncate();
        DB::table('rate_limits')->truncate();

        $exchange = ApiSystem::find(1);

        $coreJobQueue = CoreJobQueue::create([
            'class' => UpsertExchangeSymbolsJob::class,
            'arguments' => [
                'apiSystemId' => 1,
            ],
            'index' => 1,
            'queue' => 'sync',
        ]);

        // Lets tweak the 2 market data and leverage brackes to our purpose.
        CoreJobQueue::whereNotNull('canonical')->update([
            'block_uuid' => $coreJobQueue->block_uuid,
            'index' => 1,
        ]);

        CoreJobQueue::dispatch();

        return 0;
    }
}
