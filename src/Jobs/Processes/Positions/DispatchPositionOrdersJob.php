<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Positions;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

class DispatchPositionOrdersJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public float $markPrice;

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

        // Get the order same side, depending on the position direction - For the limit orders.
        $side = $this->position->direction == 'LONG' ? $dataMapper->buyType() : $dataMapper->sellType();

        // Get the order opposite side, depending on the position direction - For the profit order.
        $oppositeSide = $this->position->direction == 'LONG' ? $dataMapper->sellType() : $dataMapper->buyType();

        // Obtain the current mark price.


        Order::create([
            'position_id' => $this->position->id,
            'uuid' => (string) Str::uuid(),
            'is_syncing' => true,

        ]);
    }

    protected function getAveragePrice(float $percentage): float
    {
        $change = $this->markPrice * ($percentage / 100);
        $newPrice = $this->position->direction == 'LONG'
            ? $this->markPrice + $change
            : $this->markPrice - $change;

        return round(floor($newPrice / $this->exchangeSymbol->tick_size) * $this->exchangeSymbol->tick_size, $this->exchangeSymbol->price_precision);
    }

    protected function getQuantityFromAmountDivider(int $divider): float
    {
        return round($this->notional / $this->markPrice / $divider, $this->exchangeSymbol->quantity_precision);
    }

    protected function setTotalTradeQuantity(): void
    {
        $this->tradeQuantity = $this->notional / $this->markPrice;
    }
}
