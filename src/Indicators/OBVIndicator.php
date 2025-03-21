<?php

namespace Nidavellir\Mjolnir\Indicators;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class OBVIndicator extends BaseIndicator
{
    public string $endpoint = 'obv';

    public string $type = 'direction';

    public function direction(): ?string
    {
        info('OBVIndicator direction() with data '.json_encode($this->data));

        $obvValues = $this->data['value'] ?? null;

        if ($obvValues && count($obvValues) > 1) {
            info("OBV Comparison: {$obvValues[1]} vs {$obvValues[0]}");

            return $obvValues[1] > $obvValues[0] ? 'LONG' : 'SHORT';
        }

        return null;
    }
}
