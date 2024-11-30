<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Abstracts\BaseApiExceptionHandler;
use App\Collections\Indicators\IndicatorsActive;
use App\Models\Account;
use App\Models\ApiSystem;
use App\Models\ExchangeSymbol;
use App\Models\JobQueue;
use App\Models\TradeConfiguration;
use App\Support\Proxies\ApiDataMapperProxy;
use App\Support\Proxies\ApiProxy;
use App\Support\Proxies\RateLimitProxy;
use App\ValueObjects\ApiCredentials;
use App\ValueObjects\ApiProperties;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Jobs\GateKeepers\ApiCallJob;

class UpsertExchangeSymbolIndicatorsAndSideJob extends ApiCallJob
{
    public ApiDataMapperProxy $dataMapper;

    public ApiSystem $apiSystem;

    public ApiCredentials $credentials;

    public ExchangeSymbol $exchangeSymbol;

    public string $timeFrame;

    public TradeConfiguration $tradeConfiguration;

    public function __construct($exchangeSymbolId, $timeFrame)
    {
        $this->tradeConfiguration = TradeConfiguration::active()->first();
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'taapi');
        $this->timeFrame = $timeFrame;
        $this->exchangeSymbol = ExchangeSymbol::find($exchangeSymbolId);
        $this->dataMapper = new ApiDataMapperProxy($this->apiSystem->canonical);
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)
            ->withAccount(Account::admin($this->apiSystem->canonical));

        $this->credentials = new ApiCredentials(Account::admin('coinmarketcap')->credentials);

        $this->exceptionHandler = BaseApiExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $response = $this->apiCall();

        if (! $response) {
            return;
        }

        $indicatorData = json_decode($response->getBody(), true)['data'] ?? [];
        $directions = [];

        // Convert the indicatorData to have the "id" (canonical) as key.
        $indicatorData = collect($indicatorData)->keyBy('id')->toArray();

        foreach ((new IndicatorsActive) as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicator = new $indicatorClass;
            $continue = true;

            // Load data into the indicator.
            $indicator->load($indicatorData[$indicatorModel->canonical]['result'], $indicatorData);

            switch ($indicator->type) {
                case 'validation':
                    if (! $indicator->isValid()) {
                        $continue = false;
                    }
                    break;

                case 'direction':
                    $direction = $indicator->direction();

                    if ($direction) {
                        $directions[] = $indicator->direction();
                    } else {
                        $continue = false;
                    }
                    break;
            }

            if (! $continue) {
                $this->processNextTimeFrameOrConclude();

                return;
            }
        }

        if (count(array_unique($directions)) == 1) {
            $newSide = $directions[0];
            $this->updateSideAndNotify($newSide);

            $this->exchangeSymbol->update([
                'indicators' => $indicatorData,
                'indicators_last_synced_at' => now(),
            ]);
        } else {
            // Directions inconclusive. Proceed to next timeframe.
            $this->processNextTimeFrameOrConclude();
        }
    }

    protected function apiCall()
    {
        $apiProxy = new ApiProxy(
            $this->apiSystem->canonical,
            new ApiCredentials($this->rateLimiter->account->credentials)
        );

        $properties = $this->prepareApiProperties();

        return $this->call(function () use ($apiProxy, $properties) {
            return $apiProxy->getIndicatorValues($properties);
        });
    }

    protected function prepareApiProperties(): ApiProperties
    {
        $properties = new ApiProperties;

        $symbol = $this->dataMapper->baseWithQuote(
            $this->exchangeSymbol->symbol->token,
            $this->exchangeSymbol->quote->canonical
        );

        $properties->set('options.symbol', $symbol);
        $properties->set('options.interval', $this->timeFrame ?? config('excalibur.apis.taapi.timeframes')[0]);
        $properties->set('options.exchange', $this->exchangeSymbol->apiSystem->taapi_canonical);
        $properties->set('options.indicators', $this->getIndicatorsListForApi());

        return $properties;
    }

    protected function getIndicatorsListForApi(): array
    {
        $enrichedIndicators = [];

        foreach ((new IndicatorsActive)->where('is_apiable', true) as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicatorInstance = new $indicatorClass;

            $parameters = $indicatorModel->parameters ?? [];

            $enrichedIndicator = array_merge(
                [
                    'id' => $indicatorModel->canonical,
                    'indicator' => $indicatorInstance->endpoint,
                ],
                $parameters
            );

            $enrichedIndicators[] = $enrichedIndicator;
        }

        return $enrichedIndicators;
    }

    protected function processNextTimeFrameOrConclude(): void
    {
        $timeframes = config('excalibur.apis.taapi.timeframes');
        $currentTimeFrameIndex = array_search($this->timeFrame, $timeframes);

        if ($currentTimeFrameIndex !== false && isset($timeframes[$currentTimeFrameIndex + 1])) {
            $nextTimeFrame = $timeframes[$currentTimeFrameIndex + 1];
            JobQueue::add(
                jobClass: self::class,
                arguments: [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'timeFrame' => $nextTimeFrame,
                ],
                queueName: 'indicators'
            );
        } else {
            // End of the timeframes, and no conclusion reached.
            if ($this->exchangeSymbol->direction == null) {
                $this->exchangeSymbol->update(['is_tradeable' => false]);
            }
        }
    }

    protected function updateSideAndNotify(string $newSide): void
    {
        $currentDirection = $this->exchangeSymbol->direction;
        $currentTimeFrame = $this->exchangeSymbol->indicator_timeframe;

        // Only proceed if there is a change in direction or timeframe
        if ($currentDirection != $newSide || $currentTimeFrame != $this->timeFrame) {
            DB::transaction(function () use ($newSide) {
                $this->exchangeSymbol->lockForUpdate();
                $this->exchangeSymbol->update([
                    'direction' => $newSide,
                    'indicator_timeframe' => $this->timeFrame,
                    'is_tradeable' => true,
                ]);
            });
        }
    }

    protected function checkAndNotifyTimeFrameChange(): void
    {
        $currentTimeFrame = $this->exchangeSymbol->indicator_timeframe;
        $newTimeFrame = $this->timeFrame;

        if ($currentTimeFrame && $currentTimeFrame != $newTimeFrame) {
            $this->exchangeSymbol->update(['indicator_timeframe' => $newTimeFrame]);
        }
    }
}
