<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\TradeConfiguration;

class UpsertFearAndGreedIndexJob extends BaseApiableJob
{
    public function __construct()
    {
        $this->rateLimiter = RateLimitProxy::make('alternativeme')->withAccount(Account::admin('alternativeme'));
        $this->exceptionHandler = BaseApiExceptionHandler::make('alternativeme');
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
