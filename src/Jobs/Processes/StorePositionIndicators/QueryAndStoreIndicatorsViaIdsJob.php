<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\StorePositionIndicators;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Indicator;
use Nidavellir\Thor\Models\IndicatorHistory;
use Nidavellir\Thor\Models\Position;

class QueryAndStoreIndicatorsViaIdsJob extends BaseApiableJob
{
    public Position $position;

    public ApiProperties $apiProperties;

    public Response $response;

    public Account $apiAccount;

    public ApiDataMapperProxy $apiDataMapper;

    public string $timeframe;

    public string $indicatorIds;

    public int $retries = 20;

    public function __construct(int $positionId, string $indicatorIds, string $timeframe)
    {
        $this->position = Position::findOrFail($positionId);

        $this->timeframe = $timeframe;
        $this->indicatorIds = $indicatorIds;

        $this->rateLimiter = RateLimitProxy::make('taapi')->withAccount(Account::admin('taapi'));
        $this->exceptionHandler = BaseExceptionHandler::make('taapi');
        $this->apiDataMapper = new ApiDataMapperProxy('taapi');
        $this->apiAccount = Account::admin('taapi');
    }

    public function computeApiable()
    {
        // Just to avoid hitting a lot the rate limit threshold.
        sleep(rand(0.75, 1.25));

        $indicators = Indicator::active()->whereIn('id', explode(',', $this->indicatorIds))->get();

        $this->apiProperties = $this->apiDataMapper->prepareGroupedQueryIndicatorsProperties($this->position->exchangeSymbol, $indicators, $this->timeframe);
        $this->response = $this->apiAccount->withApi()->getGroupedIndicatorsValues($this->apiProperties);

        $response = $this->apiDataMapper->resolveGroupedQueryIndicatorsResponse($this->response);

        /**
         * For each indicator:
         * -> Load indicator with returned data from the api.
         * -> conclude.
         * -> save in the database.
         */
        $this->position->load('exchangeSymbol');

        foreach ($response['data'] as $indicatorData) {
            $canonical = $indicatorData['id'];
            $resultData = $indicatorData['result'];

            // Get the indicator model
            $indicatorModel = Indicator::firstWhere('canonical', $canonical);

            // Instantiate the indicator class
            $indicatorInstance = new $indicatorModel->class($this->position->exchangeSymbol, ['interval' => $this->timeframe]);

            // Add position ID, because we might need it.
            $resultData['position_id'] = $this->position->id;

            // Load the indicator with result data
            $indicatorInstance->load($resultData);

            // Get the conclusion
            $result = $indicatorInstance->conclusion();

            // Store it in the indicator history
            IndicatorHistory::create([
                'position_id' => $this->position->id,
                'indicator_id' => $indicatorModel->id,
                'values' => $result,
                'conclusion' => $result['conclusion'],
            ]);
        }
        // Finally save result on the core job queue entry.
        $this->coreJobQueue->update([
            'response' => $this->apiDataMapper->resolveGroupedQueryIndicatorsResponse($this->response),
        ]);

        return $this->response;
    }
}
