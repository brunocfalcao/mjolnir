<?php

namespace Nidavellir\Mjolnir\Indicators;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class OBVIndicator extends BaseIndicator
{
    public string $endpoint = 'obv';

    public string $type = 'direction';

    public function direction(): ?string
    {
        $obvValues = $this->data['value'] ?? null;

        if ($obvValues && count($obvValues) > 1) {
            return $obvValues[1] > $obvValues[0] ? 'LONG' : 'SHORT';
        }

        return null;
    }
}
