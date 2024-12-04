<?php

namespace Nidavellir\Mjolnir\Support\Proxies;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionStub;
use Nidavellir\Mjolnir\Exceptions\Stubs\BinanceExceptionStub;
use Nidavellir\Mjolnir\Exceptions\Stubs\KrakenExceptionStub;

class ExceptionStubProxy
{
    protected static array $stubs = [
        'binance' => BinanceExceptionStub::class,
        'kraken' => KrakenExceptionStub::class,
    ];

    public static function create(string $exchange, array $details = []): BaseExceptionStub
    {
        if (! array_key_exists($exchange, self::$stubs)) {
            throw new \InvalidArgumentException("Stub class for exchange {$exchange} not defined.");
        }

        $stubClass = self::$stubs[$exchange];

        return new $stubClass($details);
    }
}
