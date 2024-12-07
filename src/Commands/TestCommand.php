<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\QueryOrderJob;
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

        $blockUuid = (string) Str::uuid();

        CoreJobQueue::create([
            'class' => QueryOrderJob::class,
            'arguments' => [
                'id' => 1,
            ],
            'queue' => 'cronjobs',
        ]);

        return 0;
    }
}
