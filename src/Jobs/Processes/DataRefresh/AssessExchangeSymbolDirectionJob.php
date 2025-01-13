<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\DataRefresh;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

class AssessExchangeSymbolDirectionJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeFrame;

    public int $retries = 20;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->rateLimiter = RateLimitProxy::make('taapi')->withAccount(Account::admin('taapi'));
        $this->exceptionHandler = BaseExceptionHandler::make('taapi');
    }

    public function computeApiable()
    {
        // Just to avoid hitting a lot the rate limit threshold.
        sleep(rand(0.75, 1.25));

        $previousJobQueue = $this->coreJobQueue->getPrevious()->first();

        // Convert array to have the indicator id as the key.
        $indicatorData = collect($previousJobQueue->response['data'])->keyBy('id')->toArray();

        // Get timeframe of previous indicator calculation job.
        $this->timeFrame = $previousJobQueue->arguments['timeframe'];

        /**
         * All the indicators needs to give the same conclusion, for the
         * exchange symbol to be tradeable. If there is no conclusion,
         * we should pass to the next timeframe. If all timeframes
         * were inconclusive, then the exchange symbols is not
         * tradeable.
         */
        foreach (Indicator::active()->get() as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicator = new $indicatorClass;
            $continue = true;

            if (! array_key_exists($indicatorModel->canonical, $indicatorData)) {
                // Load all data into the indicator. It's a computed indicator.
                $indicator->load($indicatorData);
            } else {
                // Load specific indicator data.
                $indicator->load($indicatorData[$indicatorModel->canonical]['result']);
            }

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

            // Upsert the exchange symbol is is upsertable.
            if ($this->exchangeSymbol->is_upsertable) {
                $this->updateSideAndNotify($newSide);

                $this->exchangeSymbol->update([
                    'indicators' => $indicatorData,
                    'indicators_last_synced_at' => now(),
                ]);
            }
        } else {
            // Directions inconclusive. Proceed to next timeframe.
            $this->processNextTimeFrameOrConclude();
        }
    }

    protected function processNextTimeFrameOrConclude(): void
    {
        $timeframes = $this->exchangeSymbol->tradeConfiguration->indicator_timeframes;
        $currentTimeFrameIndex = array_search($this->timeFrame, $timeframes);

        if ($currentTimeFrameIndex !== false && isset($timeframes[$currentTimeFrameIndex + 1])) {
            $nextTimeFrame = $timeframes[$currentTimeFrameIndex + 1];

            $blockUuid = (string) Str::uuid();

            CoreJobQueue::create([
                'class' => QueryExchangeSymbolIndicatorJob::class,
                'queue' => 'indicators',

                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'timeframe' => $nextTimeFrame,
                ],
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);

            CoreJobQueue::create([
                'class' => AssessExchangeSymbolDirectionJob::class,
                'queue' => 'indicators',

                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                ],
                'index' => 2,
                'block_uuid' => $blockUuid,
            ]);
        } else {
            // No conclusion reached: Disable exchange symbol.
            if ($this->exchangeSymbol->direction == null) {
                $this->exchangeSymbol->update([
                    'is_tradeable' => false,
                    'indicators' => null,
                    'indicator_timeframe' => null,
                    'indicators_last_synced_at' => null,
                ]);
            }
        }
    }

    protected function updateSideAndNotify(string $newSide): void
    {
        $currentDirection = $this->exchangeSymbol->direction;
        $currentTimeFrame = $this->exchangeSymbol->indicator_timeframe;

        // Only proceed if there is a change in direction or timeframe
        if ($currentDirection != $newSide || $currentTimeFrame != $this->timeFrame) {
            $this->exchangeSymbol->update([
                'direction' => $newSide,
                'indicator_timeframe' => $this->timeFrame,

                // Exchange symbol is now tradeable.
                'is_tradeable' => true,
            ]);
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
