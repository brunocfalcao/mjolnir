<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\SyncOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class ClosePositionJob extends BaseApiableJob
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
        $residualAmountPresent = false;

        try {
            $this->position->apiClose();
        } catch (\Throwable $e) {
            // Trigger residual amount notification.
            $residualAmountPresent = true;
        }

        // Verify if the profit order was expired.
        $this->position->load(['orders', 'exchangeSymbol']);

        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        if (! $profitOrder) {
            return;
        }

        if ($profitOrder->status == 'EXPIRED' && $profitOrder->status == 'CANCELLED') {
            $this->position->update([
                'error_message' => 'Profit order was expired or cancelled, no PnL calculated. Maybe it was a manual close?',
                'closing_price' => $profitOrder->price,
            ]);
        }

        // Any order without an exchange_order_id will be marked as not synced.
        $this->position->orders()->whereNull('orders.exchange_order_id')->update(['status' => 'NOT-SYNCED']);

        // Last sync orders.
        foreach ($this->position
            ->orders
            ->where('type', '<>', 'MARKET')
            ->whereNotNull('exchange_order_id') as $order) {
            CoreJobQueue::create([
                'class' => SyncOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                ],
                'dispatch_after' => now()->addSeconds(10),
            ]);
        }

        if ($residualAmountPresent) {
            // Get residual amount present on the position, and close it.
            $apiPositions = $this->account->apiQueryPositions()->result;

            $residualAmount = 0;

            if (array_key_exists($this->position->parsedTradingPair, $apiPositions)) {
                $positionFromExchange = $apiPositions[$this->position->parsedTradingPair];
                if ($positionFromExchange['positionAmt'] != 0) {
                    $residualAmount = abs($positionFromExchange['positionAmt']);
                }
            }

            $residualAmount = api_format_quantity($residualAmount, $this->position->exchangeSymbol);

            User::admin()->get()->each(function ($user) use ($residualAmount) {
                $user->pushover(
                    message: "Position {$this->position->parsedTradingPair} closed but a residual amount of {$residualAmount} USDT is still present",
                    title: 'Position closed, but a residual amount is present',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            $this->position->updateToClosedResidual();

            return;
        }

        $this->position->updateToClosed();
    }

    public function resolveException(\Throwable $e)
    {
        User::admin()->get()->each(function ($user) {
            $user->pushover(
                message: "Position {$this->position->parsedTradingPair} not closed, due to an error: {$e->getMessage()}",
                title: 'Position closing with error!',
                applicationKey: 'nidavellir_warnings'
            );
        });

        $this->position->updateToFailed($e);
    }
}
