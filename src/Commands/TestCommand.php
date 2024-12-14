<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\QueryExchangeSymbolIndicatorJob;
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

        CoreJobQueue::create([
            'class' => QueryExchangeSymbolIndicatorJob::class,
            'arguments' => [
                'exchangeSymbolId' => 1,
            ],
            'queue' => 'cronjobs',
        ]);

        CoreJobQueue::dispatch();

        return 0;
    }
}
