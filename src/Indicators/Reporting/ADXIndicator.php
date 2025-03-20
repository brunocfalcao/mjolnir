<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class ADXIndicator extends BaseIndicator
{
    public string $endpoint = 'adx';

    /**
     * Defined method for each indicator, although the right call is compute().
     */
    public function result()
    {
        dd($this->result);
    }
}
