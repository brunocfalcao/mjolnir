<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\DataRefresh;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;

class QueryExchangeSymbolIndicatorJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public ApiProperties $apiProperties;

    public Response $response;

    public Account $apiAccount;

    public ApiDataMapperProxy $apiDataMapper;

    public ?string $timeframe;

    public int $retries = 20;

    public function __construct(int $exchangeSymbolId, ?string $timeframe = null)
    {
        $this->timeframe = $timeframe;
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->rateLimiter = RateLimitProxy::make('taapi')->withAccount(Account::admin('taapi'));
        $this->exceptionHandler = BaseExceptionHandler::make('taapi');
        $this->apiDataMapper = new ApiDataMapperProxy('taapi');
        $this->apiAccount = Account::admin('taapi');
    }

    public function computeApiable()
    {
        // Just to avoid hitting a lot the rate limit threshold.
        sleep(rand(0.75, 3.25));

        if (! $this->timeframe) {
            $this->timeframe = $this->exchangeSymbol->tradeConfiguration->indicator_timeframes[0];
        }

        $this->apiProperties = $this->apiDataMapper->prepareQueryIndicatorsProperties($this->exchangeSymbol, $this->timeframe);
        // $this->apiProperties->set('debug', ['core_job_queue_id' => $this->coreJobQueue->id]);

        $this->response = $this->apiAccount->withApi()->getIndicatorValues($this->apiProperties);

        $this->coreJobQueue->update([
            'response' => $this->apiDataMapper->resolveQueryIndicatorsResponse($this->response),
        ]);

        return $this->response;
    }
}
