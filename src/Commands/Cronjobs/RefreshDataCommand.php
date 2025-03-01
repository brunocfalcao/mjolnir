<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\QueryExchangeLeverageBracketsJob;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\QueryExchangeMarketDataJob;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\SyncAllSymbolsJob;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\UpsertExchangeSymbolsJob;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\UpsertSymbolsJob;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class RefreshDataCommand extends Command
{
    protected $signature = 'mjolnir:refresh-data {--clean : Truncate base data tables before running}';

    protected $description = 'Executes the hourly refresh cronjobs (symbols, exchange symbols, delisting, etc).';

    public function handle()
    {
        // Always truncate the log file
        // File::put(storage_path('logs/laravel.log'), '');

        // Optional truncation of base data tables if --clean is provided
        if ($this->option('clean')) {
            File::put(storage_path('logs/laravel.log'), '');
            DB::table('core_job_queue')->truncate();
            DB::table('api_requests_log')->truncate();
            DB::table('symbols')->truncate();
            DB::table('exchange_symbols')->truncate();
            DB::table('rate_limits')->truncate();
            DB::table('positions')->truncate();
            DB::table('orders')->truncate();
        }

        $blockUuid = (string) Str::uuid();

        // Upsert Symbols
        foreach (TradingPair::all() as $tradingPair) {
            // Verify if the symbol is already in the database
            if (! Symbol::where('cmc_id', $tradingPair->cmc_id)->exists()) {
                CoreJobQueue::create([
                    'class' => UpsertSymbolsJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'cmcId' => $tradingPair->cmc_id,
                    ],
                    'index' => 1,
                    'block_uuid' => $blockUuid,
                ]);
            }
        }

        CoreJobQueue::create([
            'class' => SyncAllSymbolsJob::class,
            'queue' => 'cronjobs',
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);

        // Exchange Information
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => QueryExchangeMarketDataJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'canonical' => 'market-data:'.$exchange->canonical,
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);
        }

        // Leverage Brackets
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => QueryExchangeLeverageBracketsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'canonical' => 'leverage-data:'.$exchange->canonical,
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);
        }

        // Exchange Symbols
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => UpsertExchangeSymbolsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'index' => 2,
                'block_uuid' => $blockUuid,
            ]);
        }

        /**
         * The UpsertExchangeSymbolsJob, when it's finished, will
         * create new core jobs for the indicators. Each exchange symbol
         * will have its own block_uuid with fetch indicator and calculate
         * trading side.
         */

        return 0;
    }
}
