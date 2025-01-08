<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\ClosePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class UpdatePnLAndClosingPriceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        /**
         * Get the PnL and update the position.
         */
        $apiResponse = $this->position->apiQueryTrade();
        $pnl = $apiResponse->result[0]['realizedPnl'];
        $closingPrice = $apiResponse->result[0]['price'];

        $this->position->update([
            'realized_pnl' => $pnl,
            'closing_price' => $closingPrice,
        ]);

        return $apiResponse->response;
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->update([
            'status' => 'failed',
            'is_syncing' => false,
            'error_message' => $e->getMessage(),
        ]);
    }
}