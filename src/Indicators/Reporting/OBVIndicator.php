<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class OBVIndicator extends BaseIndicator
{
    public string $endpoint = 'obv';

    public function conclusion()
    {
        $this->addTimestampForHumans();

        $obvBefore = $this->data['value'][0]; // 1h ago
        $obvNow = $this->data['value'][1];    // Now

        $position = \Nidavellir\Thor\Models\Position::find($this->data['position_id']);
        $isShort = $position && strtoupper($position->direction) === 'SHORT';

        if ($isShort) {
            // SHORT → OBV decreasing = distribution = good for bearish continuation
            $conclusion = $obvNow < $obvBefore;
        } else {
            // LONG → OBV increasing = accumulation = good for bullish continuation
            $conclusion = $obvNow > $obvBefore;
        }

        $this->data['conclusion'] = $conclusion;

        return $this->data;
    }
}
