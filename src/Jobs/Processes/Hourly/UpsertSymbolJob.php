<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseApiExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class UpsertSymbolJob extends BaseApiableJob
{
    public int $cmcId;

    public function __construct(int $cmcId)
    {
        $this->cmcId = $cmcId;
        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin('coinmarketcap'));
        $this->exceptionHandler = BaseApiExceptionHandler::make('coinmarketcap');
    }

    public function computeApiable()
    {
        $tradingPair = TradingPair::firstWhere('cmc_id', $this->cmcId);

        $symbol = Symbol::firstOrCreate([
            'cmc_id' => $this->cmcId,
        ], [
            'token' => $tradingPair->token,
        ]);

        $symbol->apiAccount = Account::admin('coinmarketcap');

        // Sync symbol with coinmarketcap data.
        $apiResponse = $symbol->apiSyncMarketData();
    }
}
