<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\ClosePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

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
        $apiResponse = $this->position->apiQueryTrade();

        if (isset($apiResponse->result[0])) {
            // Fetch PnL and closing price.
            $pnl = $apiResponse->result[0]['realizedPnl'];
            $closingPrice = $apiResponse->result[0]['price'];

            $this->position->update([
                'realized_pnl' => $pnl,
                'closing_price' => $closingPrice,
            ]);

            User::admin()->get()->each(function ($user) use ($pnl) {
                $user->pushover(
                    message: "{$this->position->parsedTradingPair} closed (PnL: {$pnl})",
                    title: 'Position closed',
                    applicationKey: 'nidavellir_positions'
                );
            });
        }

        return $apiResponse->response;
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
