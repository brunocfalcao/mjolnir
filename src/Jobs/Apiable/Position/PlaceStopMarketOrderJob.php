<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Thor\Models\User;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

class PlaceStopMarketOrderJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

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
        $dataMapper = new ApiDataMapperProxy($this->account->apiSystem->canonical);

        // Verify if we still have this position open.
        $apiPositions = $this->account->apiQueryPositions()->result;

        if (array_key_exists($this->position->parsedTradingPair, $apiPositions)) {
            // Place stop order, with the percentage given from the account.
            $stopPercentage = $this->account->stop_order_threshold_percentage;

            // Get mark price.
            $markPrice = $this->position->exchangeSymbol->apiQueryMarkPrice($this->account);

            // Set side.
            $side = null;

            // Calculate new price for the stop order given the percentage.
            if ($this->position->direction == 'LONG') {
                $side = 'SELL';
                // For LONG positions, the stop price is below the mark price.
                $stopPrice = api_format_price($markPrice * (1 - ($stopPercentage / 100)), $this->position->exchangeSymbol);
            } else {
                $side = 'BUY';
                // For SHORT positions, the stop price is above the mark price.
                $stopPrice = api_format_price($markPrice * (1 + ($stopPercentage / 100)), $this->position->exchangeSymbol);
            }

            // Time to create a new order, type 'STOP-LOSS'.
            $order = Order::create([
                'position_id' => $this->position->id,
                'side' => $side,
                'type' => 'STOP-MARKET',
                'status' => 'NEW',
                'price' => $stopPrice,
            ]);

            CoreJobQueue::create([
                'class' => PlaceOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                ],
            ]);
        }
    }

    public function resolveException(\Throwable $e)
    {
        /**
         * No need to put the position on failed, since it will trigger a new
         * position after, but we need to send a message to check what
         * happened.
         */
        User::admin()->get()->each(function ($user) {
            $user->pushover(
                message: "Error placing the stop-loss order for position {$this->position->id}. Please check!",
                title: 'Stop-loss order placing error',
                applicationKey: 'nidavellir_errors'
            );
        });
    }
}
