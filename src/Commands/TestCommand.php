<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\AssessExchangeSymbolDirectionJob;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\QueryExchangeSymbolIndicatorJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;

class TestCommand extends Command
{
    protected $signature = 'excalibur:test';

    protected $description = 'Does whatever test you want';

    public function handle()
    {
        File::put(storage_path('logs/laravel.log'), '');
        DB::table('core_job_queue')->truncate();
        DB::table('rate_limits')->truncate();

        /*
        $timeframe = '1h';
        $exchangeSymbol = ExchangeSymbol::findOrFail(1);
        $rateLimiter = RateLimitProxy::make('taapi')->withAccount(Account::admin('taapi'));
        $exceptionHandler = BaseApiExceptionHandler::make('taapi');
        $apiDataMapper = new ApiDataMapperProxy('taapi');
        $apiAccount = Account::admin('taapi');

        $apiProperties = $apiDataMapper->prepareQueryIndicatorsProperties($exchangeSymbol, $timeframe);
        $response = $apiAccount->withApi()->getIndicatorValues($apiProperties);

        $indicatorData = $apiDataMapper->resolveQueryIndicatorsResponse($response)['data'];

        $indicatorData = collect($indicatorData)->keyBy('id')->toArray();

        dd($indicatorData);
        */

        $blockUuid = (string) Str::uuid();

        CoreJobQueue::create([
            'class' => QueryExchangeSymbolIndicatorJob::class,
            'queue' => 'cronjobs',

            'arguments' => [
                'exchangeSymbolId' => 1,
                'timeframe' => '4h',
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::create([
            'class' => AssessExchangeSymbolDirectionJob::class,
            'queue' => 'cronjobs',

            'arguments' => [
                'exchangeSymbolId' => 1,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);

        CoreJobQueue::dispatch();

        return 0;
    }
}
