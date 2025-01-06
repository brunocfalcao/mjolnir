<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\BaseDataRefresh\QueryExchangeLeverageBracketsJob;
use Nidavellir\Mjolnir\Jobs\Processes\BaseDataRefresh\QueryExchangeMarketDataJob;
use Nidavellir\Mjolnir\Jobs\Processes\BaseDataRefresh\SyncAllSymbolsJob;
use Nidavellir\Mjolnir\Jobs\Processes\BaseDataRefresh\UpsertExchangeSymbolsJob;
use Nidavellir\Mjolnir\Jobs\Processes\BaseDataRefresh\UpsertSymbolsJob;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class RefreshBaseDataCommand extends Command
{
    protected $signature = 'mjolnir:refresh-base-data';

    protected $description = 'Executes the hourly refresh cronjobs (symbols, exchange symbols, delisting, etc)';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        // DB::table('core_job_queue')->truncate();
        // DB::table('api_requests_log')->truncate();
        // DB::table('symbols')->truncate();
        // DB::table('exchange_symbols')->truncate();
        // DB::table('rate_limits')->truncate();

        $blockUuid = (string) Str::uuid();

        // Upsert Symbols.
        foreach (TradingPair::all() as $tradingPair) {
            // Verify if the symbol is already on the database.
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

        // Exchange Information.
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

        // Leverage Brackets.
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

        // Exchange Symbols.
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
