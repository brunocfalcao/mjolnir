<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;
use Nidavellir\Thor\Models\Position;

class RSIIndicator extends BaseIndicator
{
    public string $endpoint = 'rsi';

    public function conclusion()
    {
        $this->addTimestampForHumans();

        $rsiBefore = $this->data['value'][0]; // 1h ago
        $rsiNow = $this->data['value'][1];    // Now

        $position = Position::find($this->data['position_id']);
        $isShort = $position && strtoupper($position->direction) === 'SHORT';

        if ($isShort) {
            // SHORT → RSI decreasing = bearish momentum = good for rebound
            $conclusion = $rsiNow < $rsiBefore;
        } else {
            // LONG → RSI increasing = bullish momentum = good for rebound
            $conclusion = $rsiNow > $rsiBefore;
        }

        $this->data['conclusion'] = $conclusion;

        return $this->data;
    }
}
