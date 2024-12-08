<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\QueryExchangeMarketDataJob;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\UpsertSymbolJob;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class HourlyCommand extends Command
{
    protected $signature = 'excalibur:hourly';

    protected $description = 'Executes the hourly refresh cronjobs (symbols, exchange symbols, delisting, etc)';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('core_job_queue')->truncate();
        DB::table('rate_limits')->truncate();

        $blockUuid = (string) Str::uuid();

        foreach (TradingPair::all() as $tradingPair) {
            CoreJobQueue::create([
                'class' => UpsertSymbolJob::class,
                'queue' => 'cronjobs',

                'arguments' => [
                    'cmcId' => $tradingPair->cmc_id,
                ],
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);
        }

        foreach (ApiSystem::allExchanges() as $exchange) {
            // Just obtain all market data for later usage.
            CoreJobQueue::create([
                'class' => QueryExchangeMarketDataJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'canonical' => 'market-data:'.$exchange->canonical,
                'index' => 2,
                'block_uuid' => $blockUuid,
            ]);
        }

        /*
        foreach (Symbol::all() as $symbol) {
            CoreJobQueue::create([
                'class' => UpsertExchangeSymbol::class,
                'queue' => 'cronjobs',

                'arguments' => [
                    'cmcId' => $tradingPair->cmc_id,
                ],
                'index' => 3,
                'block_uuid' => $blockUuid,
            ]);
        }
        */

        return 0;
    }
}
