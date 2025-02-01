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
        $this->position->load('orders');

        // Was the PROFIT order synced?
        if ($this->position->orders->where('type', 'PROFIT')
            ->whereNotNull('exchange_order_id')
            ->isEmpty()) {
            return;
        }

        $apiResponse = $this->position->apiQueryTrade();

        if (isset($apiResponse->result[0])) {
            // Fetch PnL and closing price.
            $pnl = $apiResponse->result[0]['realizedPnl'];
            $closingPrice = $apiResponse->result[0]['price'];

            $this->position->update([
                'realized_pnl' => $pnl,
                'closing_price' => $closingPrice,
            ]);

            // If the position had more than 3 limit orders filled, notify.
            if ($this->position
                ->orders
                ->where('type', 'LIMIT')
                ->where('status', 'FILLED')
                ->count() >= 1) {
                User::admin()->get()->each(function ($user) use ($pnl) {
                    $user->pushover(
                        message: "Higher profit {$this->position->parsedTradingPair} ({$this->position->direction}) closed (PnL: {$pnl})",
                        title: 'Higher profit position closed',
                        applicationKey: 'nidavellir_positions',
                        additionalParameters: ['sound' => 'cashregister ']
                    );
                });
            }
        }

        // PnL negative? Something happened. Stop opening new positions.
        if ($pnl < 0) {
            $accounts = Account::whereHas('user', function ($query) {
                $query->where('is_trader', true); // Ensure the user is a trader
            })->with('user')
                ->active()
                ->canTrade()
                ->get();

            $accounts->each->unTrade();

            User::admin()->get()->each(function ($user) {
                $user->pushover(
                    message: 'A PnL was recorded NEGATIVE. Stopping new positions from being opened. Please check ASAP!',
                    title: 'PnL negative! Something was wrong!',
                    applicationKey: 'nidavellir_errors'
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
