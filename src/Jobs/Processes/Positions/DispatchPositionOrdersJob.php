<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

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
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        /**
         * Dispatching orders are the start of the lifecycle integration. The
         * dispatch occurs using the observers of the order. We will create the
         * orders and then the system will trigger the api requests for each
         * of the order. We will not use the dispatch multiple positions at
         * once.
         */
        $dataMapper = new ApiDataMapperProxy($this->position->account->apiSystem->canonical);

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

        // Update opening price.
        $this->position->update([
            'opening_price' => $this->markPrice,
        ]);

        // Calculate the total trade quantity given the notional and mark price.
        $this->quantity = $this->getTotalTradeQuantity();

        /**
         * Create orders.
         * Limit orders, then market order, then profit order.
         */
        foreach ($this->position->order_ratios as $ratio) {
            Order::create([
                'position_id' => $this->position->id,
                'uuid' => (string) Str::uuid(),
                'is_syncing' => true,
                'type' => 'LIMIT',
                'side' => $side['same'], // LONG => Limit BUY orders.
                'quantity' => api_format_quantity($this->quantity / $ratio[1], $this->position->exchangeSymbol),
                'price' => $this->getAveragePrice($ratio[0]),
            ]);
        }
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
