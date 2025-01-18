<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\DataRefresh;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class UpsertSymbolsJob extends BaseApiableJob
{
    public int $cmcId;

    public function __construct(int $cmcId)
    {
        $this->cmcId = $cmcId;
        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin('coinmarketcap'));
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap');
    }

    public function computeApiable()
    {
        $tradingPair = TradingPair::firstWhere('cmc_id', $this->cmcId);

        $symbol = Symbol::firstOrCreate([
            'cmc_id' => $this->cmcId,
        ], [
            'token' => $tradingPair->token,
            'exchange_canonicals' => $tradingPair->exchange_canonicals,
            'category_canonical' => $tradingPair->category_canonical,
        ]);
    }
}
