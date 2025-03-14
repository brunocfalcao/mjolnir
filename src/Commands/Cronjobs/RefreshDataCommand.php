<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
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
    protected $signature = 'mjolnir:refresh-data';

    protected $description = 'Executes the hourly refresh cronjobs (symbols, exchange symbols, delisting, etc).';

    public function handle()
    {
        // Generate a unique block identifier for job grouping.
        $blockUuid = (string) Str::uuid();

        $index = 1;

        // Upsert symbols for trading pairs not yet present in the database.
        foreach (TradingPair::all() as $tradingPair) {
            if (! Symbol::where('cmc_id', $tradingPair->cmc_id)->exists()) {
                CoreJobQueue::create([
                    'class' => UpsertSymbolsJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'cmcId' => $tradingPair->cmc_id,
                    ],
                    'index' => $index,
                    'block_uuid' => $blockUuid,
                ]);
            }
        }

        // Queue a job to sync all symbols.
        CoreJobQueue::create([
            'class' => SyncAllSymbolsJob::class,
            'queue' => 'cronjobs',
            'index' => $index++,
            'block_uuid' => $blockUuid,
        ]);

        // Queue jobs for each exchange to query market data.
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => QueryExchangeMarketDataJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'canonical' => 'market-data:'.$exchange->canonical,
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        // Queue jobs for each exchange to query leverage brackets.
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => QueryExchangeLeverageBracketsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'canonical' => 'leverage-data:'.$exchange->canonical,
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        // Queue jobs for each exchange to upsert exchange symbols.
        foreach (ApiSystem::allExchanges() as $exchange) {
            CoreJobQueue::create([
                'class' => UpsertExchangeSymbolsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
                'index' => $index++,
                'block_uuid' => $blockUuid,
            ]);
        }

        // Note: The UpsertExchangeSymbolsJob will automatically trigger indicator jobs after completion.

        return Command::SUCCESS;
    }
}
