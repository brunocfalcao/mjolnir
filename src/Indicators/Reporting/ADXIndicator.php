<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class ADXIndicator extends BaseIndicator
{
    public string $endpoint = 'adx';

    /**
     * For a stop loss, if the ADX is getting lower, then the trend is losing
     * power. It means that there is a higher probability of a rebound.
     */
    public function conclusion()
    {
        $this->addTimestampForHumans();

        $newest = $this->data['value'][1];
        $oldest = $this->data['value'][0];

        $this->data['conclusion'] = $newest > $oldest ? false : true;

        return $this->data;
    }
}
