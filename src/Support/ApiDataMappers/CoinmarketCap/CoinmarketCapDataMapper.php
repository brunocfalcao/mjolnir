<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers\CoinmarketCap;

use Nidavellir\Mjolnir\Abstracts\BaseDataMapper;
use Nidavellir\Mjolnir\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSyncMarketData;

class CoinmarketCapDataMapper extends BaseDataMapper
{
    use MapsSyncMarketData;
}
