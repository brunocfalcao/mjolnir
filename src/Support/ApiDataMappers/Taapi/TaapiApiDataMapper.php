<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi;

use Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests\MapsGroupedQueryIndicators;
use Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests\MapsQueryIndicator;

class TaapiApiDataMapper
{
    use MapsGroupedQueryIndicators;
    use MapsQueryIndicator;

    public function baseWithQuote(string $token, string $quote): string
    {
        return $token.'/'.$quote;
    }
}
