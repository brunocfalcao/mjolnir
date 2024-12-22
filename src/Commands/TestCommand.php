<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\Account;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\TestJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\QueryExchangeSymbolIndicatorJob;
use Nidavellir\Mjolnir\Jobs\Processes\Hourly\AssessExchangeSymbolDirectionJob;

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
        $exceptionHandler = BaseExceptionHandler::make('taapi');
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
            'class' => TestJob::class,
            'queue' => 'cronjobs',

            'arguments' => [
                'orderId' => 1,
                'positionId' => 1,
            ],

            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        /*
        CoreJobQueue::create([
            'class' => AssessExchangeSymbolDirectionJob::class,
            'queue' => 'cronjobs',

            'arguments' => [
                'exchangeSymbolId' => 1,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
        */

        CoreJobQueue::dispatch();

        return 0;
    }
}
