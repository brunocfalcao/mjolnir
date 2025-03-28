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

        $pnl = 0;

        if (isset($apiResponse->result[0])) {
            // Fetch PnL and closing price.
            $pnl = $apiResponse->result[0]['realizedPnl'];
            $closingPrice = $apiResponse->result[0]['price'];

            $this->position->update([
                'realized_pnl' => $pnl,
                'closing_price' => $closingPrice,
            ]);

            // Notify if PnL is less than 0.
            if ($pnl < 0) {
                User::admin()->get()->each(function ($user) use ($pnl) {
                    $user->pushover(
                        message: "{$this->position->parsedTradingPair} with negative closing PnL: {$pnl})",
                        title: 'Position closed with negative PnL',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }

            // Notify if it's equal to the filled orders to notify index.
            if ($this->position
                ->orders
                ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
                ->where('status', 'FILLED')
                ->count() >= $this->account->filled_orders_to_notify) {
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
            User::admin()->get()->each(function ($user) {
                $user->pushover(
                    message: "A PnL was recorded NEGATIVE for position {$this->position->parsedTradingPair}, ID {$this->position->id}. Please check!",
                    title: 'PnL negative! Something was wrong!',
                    applicationKey: 'nidavellir_warnings'
                );
            });
        }
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e->getMessage());
    }
}
