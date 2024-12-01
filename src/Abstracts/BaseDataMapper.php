<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\ApiDataMappers\DataMapperValidator;

abstract class BaseDataMapper
{
    use DataMapperValidator;

    /*
    array:7 [
      "order_id" => 29917820287
      "symbol" => array:2 [
        0 => "FIL"
        1 => "USDT" (USDC)
      ]
      "status" => "FILLED" (CANCELLED, PARTIALLY_FILLED, NEW, EXPIRED)
      "price" => "7.2970"
      "quantity" => "65.7"
      "type" => "MARKET" (LIMIT)
      "side" => "BUY" (SELL)
    ]
    */
    abstract public function resolveOrderQueryResponse(Response $response): array;
}
