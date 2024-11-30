<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Abstracts\BaseApiExceptionHandler;
use App\Models\Account;
use App\Models\TradeConfiguration;
use App\Support\Proxies\ApiProxy;
use App\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Jobs\GateKeepers\ApiCallJob;

class UpsertFearAndGreedIndexJob extends ApiCallJob
{
    public function __construct()
    {
        $adminAccount = Account::admin('coinmarketcap');

        $this->rateLimiter = RateLimitProxy::make('alternativeme')
            ->withAccount($adminAccount);

        $this->exceptionHandler = BaseApiExceptionHandler::make('alternativeme');
    }

    public function compute()
    {
        $apiProxy = new ApiProxy('alternativeme');

        $response = $this->call(function () use ($apiProxy) {
            return $apiProxy->getFearAndGreedIndex();
        });

        if (! $response) {
            return;
        }

        $this->handleResponse($response);
    }

    protected function handleResponse($response)
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        // Dump the decoded JSON data for inspection
        $index = data_get($data, 'data.0.value');

        if ($index) {
            TradeConfiguration::query()->update(['fng_index' => $index]);
        }
    }
}
