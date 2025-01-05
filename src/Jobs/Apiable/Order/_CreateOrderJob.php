<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Order;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class _CreateOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public ExchangeSymbol $exchangeSymbol;

    public Order $order;

    public Order $marketOrder;

    public float $markPrice;

    // Specific configuration to allow more retry flexibility.
    public int $workerServerBackoffSeconds = 3;

    public int $retries = 10;
    // -----

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
        $this->exchangeSymbol = $this->position->exchangeSymbol;
    }

    public function authorize()
    {
        return $this->order->type == 'MARKET' || $this->marketOrderSynced();
    }

    public function computeApiable()
    {
        if ($this->order->type != 'MARKET') {
            $this->marketOrder = $this->position->orders->firstWhere('type', 'MARKET');
        }

        switch ($this->order->type) {
            case 'MARKET':
                info('[CreateOrderJob] - Order ID: '.$this->order->id.' -> Creating Market order on Exchange');

                /**
                 * is_syncing = true
                 * Call a Core Job Queue that will do the apiPlace
                 * Then
                 * Call another Core Job Queue that will do the apiSync
                 * Then
                 * is_syncing = false
                 *
                 * This will allow the next limit/profit orders to be
                 * processed, finally.
                 */
                $this->order->apiPlace(); // <- Change this!

                /**
                 * We can assume the mark price is exactly at this moment the same
                 * as the one from the market order.
                 */
                $this->markPrice = $this->position->orders->firstWhere('type', 'MARKET')->price;

                // Create remaining orders: Limit.
                foreach ($this->position->order_ratios as $ratio) {
                    Order::create([
                        'position_id' => $this->position->id,
                        'type' => 'LIMIT',
                        'side' => $side['same'],
                        'price' => $this->getAlignedPriceFromPercentage($this->markPrice, $ratio[0]),
                        'quantity' => api_format_quantity($this->quantity / $ratio[1], $this->position->exchangeSymbol),
                    ]);
                }

                $totalLimitOrders = count($this->position->order_ratios);

                // Create the profit order.
                Order::create([
                    'position_id' => $this->position->id,
                    'type' => 'PROFIT',
                    'side' => $side['opposite'],
                    'price' => $this->getAlignedPriceFromPercentage($this->markPrice, $this->position->profit_percentage),

                ]);
                break;

            case 'LIMIT':
            case 'PROFIT':
                // Obtain the price from the market order.

                $this->order->apiPlace();
                $this->order->apiSync();
                break;
        }
    }

    protected function marketOrderSynced()
    {
        return false;
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

    private function getAlignedPriceFromPercentage($referencePrice, float $percentage): float
    {
        $change = $referencePrice * ($percentage / 100);
        $newPrice = $this->position->direction == 'LONG'
            ? $referencePrice + $change
            : $referencePrice - $change;

        return api_format_price($newPrice, $this->position->exchangeSymbol);
    }
}
