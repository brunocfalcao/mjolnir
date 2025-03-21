<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Indicators\Reporting\ADXIndicator;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\AssessExchangeSymbolDirectionJob;
use Nidavellir\Mjolnir\Jobs\Processes\DataRefresh\QueryExchangeSymbolIndicatorJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

class IndicatorCommand extends Command
{
    protected $signature = 'debug:indicator';

    protected $description = 'Tests the new indicator classes (both grouped or single indicator query)';

    public function handle()
    {
        file_put_contents(storage_path('logs/laravel.log'), '');

        $exchangeSymbol = ExchangeSymbol::find(54);

        $exchangeSymbol->load('tradeConfiguration');

        $indicator = new ADXIndicator($exchangeSymbol, ['interval' => '1h', 'results' => 2, 'backtrack' => 1]);

        dd($indicator->compute());

        /*
        $apiDataMapper = new ApiDataMapperProxy('taapi');
        $apiAccount = Account::admin('taapi');

        $indicators = Indicator::active()->where('is_apiable', true)->where('type', 'refresh-data')->get();

        $apiProperties = $apiDataMapper->prepareGroupedQueryIndicatorsProperties($exchangeSymbol, $indicators, '1h');

        $response = $apiAccount->withApi()->getGroupedIndicatorsValues($apiProperties);

        dd($apiDataMapper->resolveGroupedQueryIndicatorsResponse($response));
        */

        /*
        $blockUuid = (string) Str::uuid();
        $index = 1;

        CoreJobQueue::create([
            'class' => QueryExchangeSymbolIndicatorJob::class,
            'queue' => 'indicators',

            'arguments' => [
                'exchangeSymbolId' => $exchangeSymbol->id,
                'timeframe' => $exchangeSymbol->tradeConfiguration->indicator_timeframes[0],
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => AssessExchangeSymbolDirectionJob::class,
            'queue' => 'indicators',

            'arguments' => [
                'exchangeSymbolId' => $exchangeSymbol->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
        */

        $this->info('All good there.');
    }
}
