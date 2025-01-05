<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;

class DispatchPositionOrdersJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public float $markPrice;

    public float $quantity;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        // Compute sides.
        $isLong = $this->position->direction == 'LONG';
        $side = [
            'same' => $isLong ? 'BUY' : 'SELL',
            'opposite' => $isLong ? 'SELL' : 'BUY',
        ];

        // Obtain the current mark price.
        $this->markPrice = $this->position->exchangeSymbol->apiQueryMarkPrice($this->account);

        if (! $this->markPrice) {
            throw new \Exception('Mark price not fetched for position ID '.$this->position->id.'. Cancelling position');
        }

        // Calculate the total trade quantity given the notional and mark price.
        $this->quantity = $this->getTotalTradeQuantity();

        /**
         * Create orders.
         * Limit orders, then market order, then profit order.
         */
        foreach ($this->position->order_ratios as $ratio) {
            Order::create([
                'position_id' => $this->position->id,
                'type' => 'LIMIT',
                'side' => $side['same'],
                'price' => $this->getAveragePrice($ratio[0]),
                'quantity' => api_format_quantity($this->quantity / $ratio[1], $this->position->exchangeSymbol),
            ]);
        }

        $totalLimitOrders = count($this->position->order_ratios);

        // Create the market order.
        Order::create([
            'position_id' => $this->position->id,
            'type' => 'MARKET',
            'side' => $side['same'],
            'quantity' => api_format_quantity($this->quantity / get_market_order_amount_divider($totalLimitOrders), $this->position->exchangeSymbol),
        ]);

        // Create the profit order.
        Order::create([
            'position_id' => $this->position->id,
            'type' => 'PROFIT',
            'side' => $side['opposite'],
        ]);

        // Dispatch all orders to be created.
        $this->dispatchOrders();
    }

    protected function dispatchOrders()
    {
        $marketOrder = $this->position->orders->firstWhere('type', 'MARKET');

        info('[DispatchPositionOrdersJob] - Dispatching order ID ' . $marketOrder->id);

        // For now, just the market order.
        CoreJobQueue::create([
            'class' => PlaceOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $marketOrder->id,
            ]
        ]);
    }

    protected function getAveragePrice(float $percentage): float
    {
        $change = $this->markPrice * ($percentage / 100);
        $newPrice = $this->position->direction == 'LONG'
            ? $this->markPrice + $change
            : $this->markPrice - $change;

        return api_format_price($newPrice, $this->position->exchangeSymbol);
    }

    protected function getTotalTradeQuantity(): float
    {
        return api_format_quantity(notional($this->position) / $this->markPrice, $this->position->exchangeSymbol);
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
