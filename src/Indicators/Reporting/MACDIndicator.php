<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;
use Nidavellir\Thor\Models\Position;

class MACDIndicator extends BaseIndicator
{
    public string $endpoint = 'macd';

    public function conclusion()
    {
        $this->addTimestampForHumans();

        $macdBefore = $this->data['valueMACD'][0];         // 1h ago
        $macdNow = $this->data['valueMACD'][1];            // Now

        $signalBefore = $this->data['valueMACDSignal'][0]; // 1h ago
        $signalNow = $this->data['valueMACDSignal'][1];    // Now

        // Retrieve Position model
        $position = Position::find($this->data['position_id']);
        $isShort = $position && strtoupper($position->direction) == 'SHORT';

        if ($isShort) {
            // SHORT → Expecting MACD to fall and be under signal
            $conclusion = $macdNow < $macdBefore && $macdNow < $signalNow;
        } else {
            // LONG → Expecting MACD to rise and be above signal
            $conclusion = $macdNow > $macdBefore && $macdNow > $signalNow;
        }

        $this->data['conclusion'] = $conclusion;

        return $this->data;
    }
}
