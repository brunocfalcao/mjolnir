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

        $totalLimitOrders = $this->position->total_limit_orders;

        //info('[VerifyOrderNotionalOnMarketOrderJob] - Position Direction: '.$this->position->direction);

        //info("[VerifyOrderNotionalOnMarketOrderJob] - Minimum Notional for {$this->position->parsedTradingPair}: ".$this->position->exchangeSymbol->min_notional);

        //info('[VerifyOrderNotionalOnMarketOrderJob] - Position Margin: '.$this->position->margin);

        //info('[VerifyOrderNotionalOnMarketOrderJob] - Position Notional: '.notional($this->position));

        $totalTradeQuantity = $this->getTotalTradeQuantity();

        //info("[VerifyOrderNotionalOnMarketOrderJob] - Price: {$this->markPrice}");
        //info('[VerifyOrderNotionalOnMarketOrderJob] - Divider: '.get_market_order_amount_divider($this->position->total_limit_orders));
        //info("[VerifyOrderNotionalOnMarketOrderJob] - Limit Orders: {$this->position->total_limit_orders}");

        $marketOrderQuantity = api_format_quantity(notional($this->position) /
                               $this->markPrice /
                               get_market_order_amount_divider(
                                   $this->position->total_limit_orders
                               ), $this->position->exchangeSymbol);

        //info('[VerifyOrderNotionalOnMarketOrderJob] - Market Order Quantity: '.$marketOrderQuantity);

        //info('[VerifyOrderNotionalOnMarketOrderJob] - Market Order Size: '.api_format_price($marketOrderQuantity * $this->markPrice, $this->position->exchangeSymbol).' USDT');

        $marketOrderSize = api_format_price($marketOrderQuantity * $this->markPrice, $this->position->exchangeSymbol);

        if ($marketOrderSize < $this->position->exchangeSymbol->min_notional) {
            // Stop Job Queue sequence and fail position, silently.
            $message = "Market order size ({$marketOrderSize}) less than exchange symbol minimum notional ({$this->position->exchangeSymbol->min_notional})";

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
