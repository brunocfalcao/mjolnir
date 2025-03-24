<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;
use Nidavellir\Thor\Models\Position;

class CandleIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public function conclusion()
    {
        $this->addTimestampForHumans();

        $closeBefore = $this->data['close'][0]; // 1h ago
        $closeNow = $this->data['close'][1];    // Now

        // Get Position model to determine direction
        $position = Position::find($this->data['position_id']);
        $isShort = $position && strtoupper($position->direction) === 'SHORT';

        if ($isShort) {
            // SHORT: We want price to show signs of rejection → close decreasing
            $conclusion = $closeNow < $closeBefore;
        } else {
            // LONG: We want price to start recovering → close increasing
            $conclusion = $closeNow > $closeBefore;
        }

        $this->data['conclusion'] = $conclusion;

        return $this->data;
    }
}
