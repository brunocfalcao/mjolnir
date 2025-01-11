<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\DataRefresh;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\TradeConfiguration;

class UpsertFearAndGreedIndexJob extends BaseApiableJob
{
    public function __construct()
    {
        $this->rateLimiter = RateLimitProxy::make('alternativeme')->withAccount(Account::admin('alternativeme'));
        $this->exceptionHandler = BaseExceptionHandler::make('alternativeme');
    }

    public function computeApiable()
    {
        TradeConfiguration::all()->each(function ($tradeConfiguration) {
            $response = $tradeConfiguration->apiFngQuery();
            $tradeConfiguration->update([
                'fng_index' => $response->result['fng_index'],
            ]);
        });
    }
}
