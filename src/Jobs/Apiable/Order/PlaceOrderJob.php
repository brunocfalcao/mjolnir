<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\CancelOpenOrdersJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\ClosePositionJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class PlaceOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    // Specific configuration to allow more retry flexibility.
    public int $workerServerBackoffSeconds = 3;

    public int $retries = 10;
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
        $orders = $this->order->position->orders;

        // Any order failed?
        if ($orders->where('status', 'FAILED')->isNotEmpty()) {
            throw new \Exception('Other orders failed, aborting place orders.');
        }

        // Are we placing another MARKET, PROFIT, or MARKET-CANCEL order by mistake?
        if (in_array($this->order->type, ['MARKET', 'PROFIT', 'MARKET-CANCEL']) &&
            $orders->whereIn('type', ['MARKET', 'PROFIT', 'MARKET-CANCEL'])
                ->whereNotNull('exchange_order_id')
                ->isNotEmpty()) {
            throw new \Exception('A 2nd market, profit, or market-cancel order is trying to be created for the same position!');
        }

        // Are we trying to create another limit order more than the totalLimitOrders?
        $totalLimitOrders = count($this->order->position->orders_ratio);
        if ($this->order->type == 'LIMIT' &&
            $orders->where('type', 'LIMIT')->count() > $totalLimitOrders) {
            throw new \Exception('Total limit orders where already created!');
        }

        // First the market order, then the others.
        return $this->order->type == 'MARKET' || $this->marketOrderSynced();
    }

    public function computeApiable()
    {
        // info('[PlaceOrderJob] - Order ID: '.$this->order->id.', placing order on API...');

        /**
         * Small exception for the profit order. If the quantity is null then
         * we get the profit order quantity from the market order.
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

        $apiResponse = $this->order->apiPlace();

        // Sync order.
        $this->order->apiSync();

        // info('[PlaceOrderJob] - Order ID: '.$this->order->id.', order placed and synced with exchange id '.$this->order->exchange_order_id);

        return $apiResponse->response;
    }

    public function marketOrderSynced()
    {
        return $this->order->position->orders()
            ->where('type', 'MARKET')
            ->where('status', 'FILLED')
            ->exists();
    }

    private function getNewPriceFromPercentage(float $referencePrice, float $percentage): float
    {
        $change = $referencePrice * ($percentage / 100);
        $newPrice = $this->position->direction == 'LONG'
            ? $referencePrice + $change
            : $referencePrice - $change;

        return api_format_price($newPrice, $this->position->exchangeSymbol);
    }

    public function resolveException(\Throwable $e)
    {
        /**
         * In order to avoid this order being processed again, since it might
         * be on the status new, we need to change it immediately to the
         * status failed.
         */
        $this->order
            ->position
            ->updateToFailed('Position is marked as failed because we are cancelling all open orders (an exception was triggered)');

        CoreJobQueue::create([
            'class' => CancelOpenOrdersJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $this->order->position->id,
            ],
        ]);

        CoreJobQueue::create([
            'class' => ClosePositionJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->order->position->id,
            ],
        ]);

        $this->order->updateToFailed($e);

        $this->coreJobQueueStatusUpdated = false;
    }
}
