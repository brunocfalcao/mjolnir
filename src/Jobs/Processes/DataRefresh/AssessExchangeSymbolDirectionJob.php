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

    public bool $shouldCleanIndicatorData;

    public int $retries = 20;

    public function __construct(int $exchangeSymbolId, bool $shouldCleanIndicatorData = true)
    {
        $this->shouldCleanIndicatorData = $shouldCleanIndicatorData;
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->rateLimiter = RateLimitProxy::make('taapi')->withAccount(Account::admin('taapi'));
        $this->exceptionHandler = BaseExceptionHandler::make('taapi');
    }

    public function computeApiable()
    {
        $this->exchangeSymbol->load('symbol');

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
        foreach (Indicator::active()->apiable()->where('type', 'refresh-data')->get() as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicator = new $indicatorClass($this->exchangeSymbol, ['interval' => $this->timeFrame]);
            $indicator->symbol = $this->exchangeSymbol->symbol->token;
            $continue = true;

            /**
             * A computed indicator doesn't have a key inside the indicator data.
             * Later we need to change this logic.
             */
            if (! array_key_exists($indicatorModel->canonical, $indicatorData)) {
                // Load all data into the indicator. It's a computed indicator.
                $indicator->load($indicatorData);
            } else {
                $indicator->load($indicatorData[$indicatorModel->canonical]['result']);
            }

            $result = '';

            $conclusion = $indicator->conclusion();

            // info("Indicator {$indicatorModel->canonical} on timeframe {$this->timeFrame} for symbol {$indicator->symbol} conclusion was " . $conclusion);

            // Indicator valid as TRUE or FALSE.
            if (is_bool($conclusion) && $conclusion == false) {
                $result = 'Indicator conclusion returned false';
                $continue = false;
            }

            // Indicator valid as LONG or SHORT.
            if (is_string($conclusion)) {
                if ($conclusion == 'LONG' || $conclusion == 'SHORT') {
                    if ($conclusion) {
                        $directions[] = $conclusion;
                    }
                } else {
                    $continue = false;
                    $result = 'Indicator conclusion not LONG neither SHORT';
                }
            }

            // Indicator didnt conclude, or returned void.
            if ($conclusion == null || ! isset($conclusion)) {
                $result = 'Indicator conclusion is NULL or not set';
                $continue = false;
            }

            if (! $continue) {
                $this->processNextTimeFrameOrConclude();
                $this->coreJobQueue->update(['response' => 'Timeframe not concluded because: '.$result]);

                return;
            }
        }

        // info('Conclusion directions:' . json_encode($directions));

        if (count(array_unique($directions)) == 1) {
            $newSide = $directions[0];

            // Update the indicators only if the exchange symbol is upsertable.
            /**
             * If the direction is contrary to the current direction, it can
             * only change if the timeframe is higher than the index on the
             * timeframes array. This will cancel timeframes that are too
             * short to change the direction of the token, avoiding false
             * reversals.
             */

            // No current side?
            if ($this->exchangeSymbol->direction == null) {
                // Update exchange symbol with the new direction.
                $this->coreJobQueue->update(['response' => "Exchange Symbol {$this->exchangeSymbol->symbol->token} indicator VALIDATED"]);

                //info("Exchange Symbol {$this->exchangeSymbol->symbol->token}{$this->exchangeSymbol->quote->canonical} set to {$newSide}/{$this->timeFrame} (indicator direction was null)");

                // $this->updateSideAndNotify($newSide);

                $this->exchangeSymbol->update([
                    'direction' => $newSide,
                    'indicator_timeframe' => $this->timeFrame,
                    'is_tradeable' => true,
                    'indicators' => $indicatorData,
                    'indicators_last_synced_at' => now(),
                ]);

                return;
            }

            // New side different from the current side?
            if ($this->exchangeSymbol->direction != $newSide) {
                // We have a direction. Are we trying to change the direction?
                if ($this->exchangeSymbol->direction != $newSide && $this->exchangeSymbol->direction != null) {
                    //info("{$indicator->symbol} current direction is {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicator_timeframe} and should change to {$newSide} on timeframe {$this->timeFrame}");

                    // Check if we can change the direction.
                    $timeframes = $this->exchangeSymbol->tradeConfiguration->indicator_timeframes;
                    $leastTimeFrameIndex = $this->exchangeSymbol->tradeConfiguration->least_changing_timeframe_index;
                    $currentTimeFrameIndex = array_search($this->timeFrame, $timeframes);

                    if ($leastTimeFrameIndex > $currentTimeFrameIndex) {
                        /**
                         * No deal. We cannot change the indicator since the timeframe is not high enough to
                         * conclude the direction.
                         *
                         * Do not clean indicator data in case we don't find the same conclusion in a higher timeframe.
                         * This will allow the indicator to stay with the same direction until an opposite direction
                         * is concluded in a higher timeframe. Until then, we don't change the direction.
                         */
                        $this->shouldCleanIndicatorData = false;

                        $this->coreJobQueue->update(['response' => 'Indicator CONCLUDED but not on the minimum timeframe to change. Lets continue...']);

                        //info("Symbol {$indicator->symbol} didnt change direction because needed a timeframe of {$timeframes[$leastTimeFrameIndex]} and it got a timeframe of {$this->timeFrame}");

                        // We need to try to process the next timeframe, but we don't clean the exchange symbol.
                        $this->processNextTimeFrameOrConclude();

                        return;
                    }
                }

                // Update exchange symbol with the new direction.
                $this->coreJobQueue->update(['response' => "Exchange Symbol {$this->exchangeSymbol->symbol->token} indicator VALIDATED"]);

                //info("Exchange Symbol {$this->exchangeSymbol->symbol->token} indicator updated to {$newSide} on timeframe {$this->timeFrame}");

                // $this->updateSideAndNotify($newSide);

                $this->exchangeSymbol->update([
                    'direction' => $newSide,
                    'indicator_timeframe' => $this->timeFrame,
                    'is_tradeable' => true,
                    'indicators' => $indicatorData,
                    'indicators_last_synced_at' => now(),
                ]);

                return;
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

        if (isset($timeframes[$currentTimeFrameIndex + 1])) {
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
                    'shouldCleanIndicatorData' => $this->shouldCleanIndicatorData,
                ],
                'index' => 2,
                'block_uuid' => $blockUuid,
            ]);
        } else {
            if ($this->shouldCleanIndicatorData && $this->exchangeSymbol->direction != null) {
                // No conclusion reached: Disable exchange symbol.
                $this->coreJobQueue->update(['response' => 'Exchange Symbol WITHOUT CONCLUSION. Stopped']);

                //info("Symbol {$this->exchangeSymbol->symbol->token}/{$this->exchangeSymbol->quote->canonical} didnt get any direction conclusion, so we are cleaning its indicator data");

                $this->exchangeSymbol->update([
                    'direction' => null,
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
