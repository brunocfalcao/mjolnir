<?php

namespace Nidavellir\Mjolnir\Indicators\Reporting;

use Nidavellir\Mjolnir\Abstracts\BaseIndicator;

class MFIIndicator extends BaseIndicator
{
    public string $endpoint = 'mfi';

    /**
     * For a stop loss, if the MFI is getting lower, then the trend is losing
     * power. It means that there is a higher probability of a rebound.
     */
    public function conclusion()
    {
        $this->addTimestampForHumans();

        $newest = $this->data['value'][1];
        $oldest = $this->data['value'][0];

        if ($newest > $oldest) {
            // False, means RED, means the trend is still strong, bad for rebound.
            $conclusion = false;
        } else {
            // True, means GREEN, means the trend is losing strength, good for the rebound.
            $conclusion = true;
        }

        $this->data['conclusion'] = $conclusion;

        return $this->data;
    }
}
