<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi;

use Nidavellir\Mjolnir\Support\ApiDataMappers\Taapi\ApiRequests\MapsQueryIndicators;

class TaapiApiDataMapper
{
    use MapsQueryIndicators;

    public function baseWithQuote(string $token, string $quote): string
    {
        return $token.'/'.$quote;
    }
}
