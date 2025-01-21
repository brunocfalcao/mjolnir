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

        $marketOrderNotional = api_format_price($quantityForMarketOrder * $this->markPrice, $this->position->exchangeSymbol);

        if ($marketOrderNotional < $this->position->exchangeSymbol->min_notional) {
            // Stop Job Queue sequence and fail position, silently.
            $message = "Market order notional ({$marketOrderNotional}) less than exchange symbol minimum notional ({$this->position->exchangeSymbol->min_notional})";

            $this->coreJobQueue->updateToFailed($message, true);
            $this->position->updateToFailed($message);

            // Flag the parent class that all statuses were updated.
            $this->coreJobQueueStatusUpdated = true;
        }
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
