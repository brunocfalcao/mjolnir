<?php

namespace Nidavellir\Mjolnir\Indicators;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

/**
 * Verifies if there was a specific amplitude percentage in the respective
 * candle timeframe. This is an indicator that will cancel any conclusion in
 * case of an extreme fluctuation on a token price (e.g: > 30% in 1h for instance).
 */
class AmplitudeThresholdIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public string $type = 'validation';

    protected float $amplitude = 30.0; // 30% default threshold

    public function isValid(): bool
    {
        if (empty($this->data['low']) ||
            empty($this->data['high']) ||
            count($this->data['low']) < 2 ||
            count($this->data['high']) < 2
        ) {
            info('[AmplitudeThresholdIndicator] - Not enough data to analyze, returning FALSE.');
            return false; // Not enough data to analyze
        }

        // Identify the oldest (first) and newest (last) candle indices
        $oldestIndex = 0;
        $newestIndex = count($this->data['low']) - 1;

        // Extract relevant values
        $oldestLow = $this->data['low'][$oldestIndex];
        $oldestHigh = $this->data['high'][$oldestIndex];
        $newestLow = $this->data['low'][$newestIndex];
        $newestHigh = $this->data['high'][$newestIndex];

        // Calculate the amplitude (percentage change from oldest LOW to newest HIGH)
        $lowPrice = min($oldestLow, $newestLow);
        $highPrice = max($oldestHigh, $newestHigh);

        $priceDifference = $highPrice - $lowPrice;
        $percentageAmplitude = ($priceDifference / $lowPrice) * 100;

        info("[AmplitudeThresholdIndicator] - {$this->symbol} - Oldest Low: $oldestLow, Newest Low: $newestLow, Oldest High: $oldestHigh, Newest High: $newestHigh");
        info("[AmplitudeThresholdIndicator] - {$this->symbol} - Calculated Amplitude: $percentageAmplitude% (Threshold: {$this->amplitude}%)");

        if ($percentageAmplitude > $this->amplitude) {
            info("[AmplitudeThresholdIndicator] - {$this->symbol} - Amplitude exceeded threshold, returning FALSE.");
            return false;
        }

        info("[AmplitudeThresholdIndicator] - {$this->symbol} - Amplitude within threshold, returning TRUE.");
        return true;
    }
}
