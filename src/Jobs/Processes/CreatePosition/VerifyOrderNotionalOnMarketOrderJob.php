<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class VerifyOrderNotionalOnMarketOrderJob extends BaseApiableJob
{
    public Account $account;

    public Position $position;

    public ApiSystem $apiSystem;

    public array $balance;

    public float $markPrice;

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
        $this->markPrice = $this->position->exchangeSymbol->apiQueryMarkPrice($this->account);

        $totalLimitOrders = count($this->position->order_ratios);

        $quantity = $this->getTotalTradeQuantity();

        $quantityForMarketOrder = api_format_quantity($quantity / get_market_order_amount_divider($totalLimitOrders), $this->position->exchangeSymbol);

        $this->coreJobQueue->updateToFailed('Market order quantity will be zero, this exchange symbol cannot be selected', true);
        $this->position->updateToFailed('Market order quantity will be zero, this exchange symbol cannot be selected');
    }

    protected function getTotalTradeQuantity(): float
    {
        return api_format_quantity(notional($this->position) / $this->markPrice, $this->position->exchangeSymbol);
    }

    public function resolveException(\Throwable $e)
    {
        $this->position->updateToFailed($e);
    }
}
