<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class VerifyIfTradingPairIsOpenedJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public float $balance;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
        $this->exchangeSymbol = $this->position->exchangeSymbol;
    }

    public function computeApiable()
    {
        $apiPositions = $this->account->apiQueryPositions()->result;

        // Do we already have an opened position with this trading pair?
        if (array_key_exists($this->position->parsedTradingPair, $apiPositions)) {
            $this->position->updateToFailed("This trading pair ({$this->position->parsedTradingPair}) already have an opened position! Aborting");
            $this->coreJobQueue->updateToFailed("This trading pair ({$this->position->parsedTradingPair}) already have an opened position! Aborting", true);
        }
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e);
    }
}
