<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Processes\RollbackPosition\RollbackPositionLifecycleJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class PlaceOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    // Specific configuration to allow more retry flexibility.
    public int $workerServerBackoffSeconds = 10;

    public int $retries = 3;
    // -----

    public function __construct(int $orderId)
    {
        $this->order = Order::with(['position.account.apiSystem'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function authorize()
    {
        // Any order failed?
        if ($this->order->position->orders->where('status', 'FAILED')->isNotEmpty()) {
            throw new \Exception('Other orders failed, aborting place orders.');
        }

        // First the market order, then the others.
        return $this->order->type == 'MARKET' || $this->marketOrderSynced();
    }

    public function computeApiable()
    {
        // Verify if conditions are met to place this order. If not, silently abort.
        $result = $this->verifyConditions();

        if ($result !== true) {
            // info("[PlaceOrderJob] Updating Order ID {$this->order->id} to cancelled");

            $this->order->updateToInvalid($result);

            User::admin()->get()->each(function ($user) use ($result) {
                $user->pushover(
                    message: "[PlaceOrderJob] - Order ID {$this->order->id}, conditions not met: {$result}",
                    title: 'Place Order error',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            /**
             * Finish the core job queue. We don't need to trigger an exception
             * because if so, then it might remove all the already present orders.
             * We will check what's going on later, via pushover message.
             */

            return;
        }

        // info('[PlaceOrderJob] - Order ID: '.$this->order->id.', placing order on API...');

        /**
         * Small exception for the profit order. If the quantity is null then
         * we get the profit order quantity from the market order. It always
         * need to have the same quantity, even as the first time.
         */
        if ($this->order->type == 'PROFIT') {
            $marketOrder = $this->order->position->orders->firstWhere('type', 'MARKET');

            if (! $this->order->quantity) {
                if (! $marketOrder) {
                    throw new \Exception('Cannot place Profit order because the market order doesnt exist. Aborting');
                }

                $this->order->update([
                    'quantity' => $marketOrder->quantity,
                ]);
            }

            if (! $this->order->price) {
                if (! $marketOrder) {
                    throw new \Exception('Cannot place Profit order because the market order doesnt exist. Aborting');
                }
            }

            $this->order->update([
                'price' => $this->getNewPriceFromPercentage($marketOrder->price, $this->position->profit_percentage),
            ]);
        }

        // The api place gets data from the profit order db entry.
        $apiResponse = $this->order->apiPlace();

        // Sync order.
        $this->order->apiSync();

        // info('[PlaceOrderJob] - Order ID: '.$this->order->id.', order placed and synced with exchange id '.$this->order->exchange_order_id);
    }

    public function verifyConditions()
    {
        $orders = $this->order->position->orders;

        $marketOrderFilled = $orders->where('type', 'MARKET')
            ->where('status', 'FILLED')
            ->isNotEmpty();

        $profitOrderFilled = $orders->where('type', 'PROFIT')
            ->where('status', 'FILLED')
            ->isNotEmpty();

        $marketCancelOrderFilled = $orders->where('type', 'MARKET-CANCEL')
            ->where('status', 'FILLED')
            ->isNotEmpty();

        $allLimitOrdersFilled = $orders->whereIn('type', ['LIMIT', 'LIMIT-MAGNET'])
            ->whereIn('status', ['FILLED', 'PARTIALLY_FILLED'])
            ->count() == $this->order->position->total_limit_orders;

        // Are we placing another MARKET, PROFIT, or MARKET-CANCEL order by mistake?
        if ($this->order->type == 'MARKET' && $marketOrderFilled) {
            return 'A 2nd MARKET order is trying to be created for the same position! Aborting.';
        }

        if ($this->order->type == 'PROFIT' && $profitOrderFilled) {
            return 'A 2nd PROFIT order is trying to be created for the same position! Aborting.';
        }

        if ($this->order->type == 'MARKET-CANCEL' && $marketCancelOrderFilled) {
            return 'A 2nd MARKET-CANCEL order is trying to be created for the same position! Aborting.';
        }

        if ($this->order->type == 'LIMIT' && $allLimitOrdersFilled) {
            return 'An extra LIMIT order is trying to be placed when all limit orders are filled! Aborting';
        }

        return true;
    }

    public function marketOrderSynced()
    {
        return $this->order->position->orders()
            ->where('type', 'MARKET')
            ->where('status', 'FILLED')
            ->whereNotNull('exchange_order_id')
            ->exists();
    }

    private function getNewPriceFromPercentage(float $referencePrice, float $percentage): float
    {
        $change = $referencePrice * ($percentage / 100);

        // For LONG, the price increases. For SHORT, it decreases.
        $newPrice = $this->position->direction == 'LONG'
        ? $referencePrice + $change
        : $referencePrice - $change;

        return api_format_price($newPrice, $this->position->exchangeSymbol);
    }

    public function resolveException(\Throwable $e)
    {
        // Position is already in rollbacking mode? -- Skip resolve exception.
        if ($this->position->isRollbacking() || $this->position->isRollbacked()) {
            return;
        }

        /**
         * We only rollback positions that are being created, not the ones
         * that are already active.
         */
        if ($this->position->status == 'new') {
            CoreJobQueue::create([
                'class' => RollbackPositionLifecycleJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->order->position->id,
                ],
            ]);
        } else {
            // Update this order to failed, only. Send pushover message.
            $this->order->updateToFailed($e);
        }

        $this->coreJobQueueStatusUpdated = false;
    }
}
