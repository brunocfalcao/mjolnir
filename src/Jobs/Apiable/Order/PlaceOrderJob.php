<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
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
        // First the market order, then the others.
        return $this->order->type == 'MARKET' || $this->marketOrderSynced();
    }

    public function computeApiable()
    {
        info('[PlaceOrderJob] - Order ID: '.$this->order->id.', placing order on API...');

        $this->order->changeToSyncing();

        /**
         * Small exception for the profit order. If the quantity is null then
         * we get the profit order quantity from the market order.
         */
        if ($this->order->type == 'PROFIT') {
            if (! $this->order->quantity) {
                $marketOrder = $this->order->position->orders->firstWhere('type', 'MARKET');

                if (! $marketOrder) {
                    throw new \Exception('Cannot place Profit order because the market order doesnt exist. Aborting');
                }

                $this->order->update([
                    'quantity' => $marketOrder->quantity,
                ]);
            }

            if (! $this->order->price) {
                $marketOrder = $this->order->position->orders->firstWhere('type', 'MARKET');

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

        $this->order->changeToSynced();

        info('[PlaceOrderJob] - Order ID: '.$this->order->id.', order placed and synced with exchange id '.$this->order->exchange_order_id);

        return $apiResponse->response;
    }

    public function marketOrderSynced()
    {
        return $this->order->position->orders()
            ->where('is_syncing', false)
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
        // Cancels all open orders (except the market order itself).
        // $this->order->position->apiCancelAllOrders();

        // Opens an opposite market order with same quantity to close position.
        // $this->order->position->apiCancelMarketOrder();

        // Stop the order.
        $this->order->update([
            'status' => 'FAILED',
            'is_syncing' => false,
            'error_message' => $e->getMessage(),
        ]);
    }
}