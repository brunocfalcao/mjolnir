<?php

namespace Nidavellir\Mjolnir\Indicators;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class EMAIndicator extends BaseIndicator
{
    public string $endpoint = 'ema';

    public string $type = 'direction';

    public function direction(): ?string
    {
        $emaValues = $this->data['value'] ?? null;

        if ($emaValues && count($emaValues) > 1) {
            return $emaValues[1] > $emaValues[0] ? 'LONG' : 'SHORT';
        }

        return null;
    }
}
