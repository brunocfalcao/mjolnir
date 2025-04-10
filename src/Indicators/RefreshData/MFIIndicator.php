<?php

namespace Nidavellir\Mjolnir\Indicators\RefreshData;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class MFIIndicator extends BaseIndicator
{
    public string $endpoint = 'mfi';

    public string $type = 'value';

    public function conclusion()
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        if (! array_key_exists(0, $this->data['value'])) {
            return 0;
        }

        // Major number to keep the trend solid (e.g. >= 20).
        return $this->data['value'][0] >= 15;
    }
}
