<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Jobs\Processes\StorePositionIndicators\StorePositionIndicatorsLifecycleJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Indicator;
use Nidavellir\Thor\Models\Position;

class StorePositionIndicatorsCommand extends Command
{
    protected $signature = 'mjolnir:store-position-indicators {--type= : The type for the indicators import} {--timeframe= : The timeframe for the indicators import (required)} {--clean : Truncate relevant tables before execution (optional)}';

    protected $description = 'Calls API and stores all position indicators for a specific category';

    public function handle()
    {
        if ($this->option('clean')) {
            file_put_contents(storage_path('logs/laravel.log'), '');
            $this->cleanDatabase();
        }

        $type = $this->option('type');
        $timeframe = $this->option('timeframe');

        if (! $type) {
            $this->error('The --category option is required.');

            return Command::FAILURE;
        }

        if (! $type) {
            $this->error('The --timeframe option is required.');

            return Command::FAILURE;
        }

        foreach (Position::active()->get() as $position) {
            Indicator::active()->apiable()->where('type', $type)->chunk(3, function ($indicators) use ($position, $timeframe) {

                $indicatorIds = implode(',', $indicators->pluck('id')->toArray());

                CoreJobQueue::create([
                    'class' => StorePositionIndicatorsLifecycleJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'positionId' => $position->id,
                        'indicatorIds' => $indicatorIds,
                        'timeframe' => $timeframe,
                    ],
                ]);
            });
        }
    }

    private function cleanDatabase()
    {
        DB::statement('TRUNCATE TABLE indicators_history');
        DB::statement('TRUNCATE TABLE core_job_queue');
        DB::statement('TRUNCATE TABLE application_logs');
        DB::statement('TRUNCATE TABLE api_requests_log');
        DB::statement('TRUNCATE TABLE positions');
        DB::statement('TRUNCATE TABLE orders');
    }
}
