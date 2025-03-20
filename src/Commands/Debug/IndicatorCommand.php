<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Indicators\Reporting\ADXIndicator;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

class IndicatorCommand extends Command
{
    protected $signature = 'debug:indicator';

    protected $description = 'Tests the new indicator classes (both grouped or single indicator query)';

    public function handle()
    {
        $exchangeSymbol = ExchangeSymbol::find(3);

        $exchangeSymbol->load('tradeConfiguration');

        /*
        $indicator = new ADXIndicator($exchangeSymbol, ['interval' => '1h', 'results' => 5]);
        dd($indicator->compute());
        */

        $apiDataMapper = new ApiDataMapperProxy('taapi');
        $apiAccount = Account::admin('taapi');

        $indicators = Indicator::active()->where('is_apiable', true)->where('type', 'refresh-data')->get();

        $apiProperties = $apiDataMapper->prepareGroupedQueryIndicatorsProperties($exchangeSymbol, $indicators, '1h');
        $response = $apiDataMapper->resolveGroupedQueryIndicatorsResponse($apiAccount->withApi()->getGroupedIndicatorsValues($apiProperties));

        dd($response);

        $this->info('All good there.');
    }
}
