<?php

namespace Nidavellir\Mjolnir\Indicators;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class ADXIndicator extends BaseIndicator
{
    public string $endpoint = 'adx';

    public string $type = 'validation';

    public function isValid(): bool
    {
        // Should be >= 25 to return true.
        return $this->data['value'][0] >= 20;
    }
}
