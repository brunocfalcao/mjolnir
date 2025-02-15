<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\TradeConfiguration;

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

        $tradeConfiguration = TradeConfiguration::default()->first();

        // Update total limit orders.
        $this->position->update([
            'total_limit_orders' => $tradeConfiguration->total_limit_orders,
        ]);

        // Obtain the current mark price.
        $this->markPrice = $this->position->exchangeSymbol->apiQueryMarkPrice($this->account);

        // Mandatory to have the mark price for the position, to make calculations.
        if (! $this->markPrice) {
            throw new \Exception('Mark price not fetched for position ID '.$this->position->id.'. Cancelling position');
        }

        /**
         * Last verification to see if we have more than the max number of concurrent positions open.
         * This might happen in rare scenarios where the bot is closing and opening positions super
         * fast, like when the market is crashing or boosting.
         */
        $openPositions = Position::active()->where('account_id', $this->account->id)->get()->count();

        if ($openPositions > $this->account->max_concurrent_trades) {
            throw new \Exception('Last open positions check failed: Total opened positions:'.$openPositions.'. Aborting orders dispatch!');
        }

        /**
         * The quantity calculation for each order follows the Market to Limit orders.
         *
         * We first calculate the quantity for the limit order. And then we
         * multiply that calculation on each of the next limit orders using
         * the martingale strategy.
         */

        // Don't format. Just get the raw number.
        $marketOrderQuantity = api_format_quantity(notional($this->position) /
                               $this->markPrice /
                               get_market_order_amount_divider(
                                   $this->position->total_limit_orders
                               ), $this->position->exchangeSymbol);

        // info('[DispatchPositionOrdersJob] - MarketOrderQuantity (no rounding): '.$marketOrderQuantity);

        /**
         * Now, for each limit order we will MULTIPLY the quantity to obtain
         * the correct quantity for the limit order.
         */
        $percentageGap = $this->position->direction == 'LONG' ?
            $tradeConfiguration->percentage_gap_long :
            $tradeConfiguration->percentage_gap_short;

        /**
         * Lets get the magnet zone percentage, so we can calculate both
         * the activation and trigger price. The activation is when the
         * price reaches this amount, it will place magnet_status = 'activated'.
         *
         * The trigger price is if the price rebounds to this price, then
         * it will automatically trigger the magnetization (cancelation of
         * the limit order and an immediate market order of the quantity of
         * the limit order), with magnet_status = triggered.
         *
         * If the trigger price wasn't reached, and the limit order was
         * normally achieved, then the magnet_status = cancelled.
         */
        $magnetPercentage = TradeConfiguration::default()->first()->magnet_zone_percentage / 100;

        /**
         * The magnet activation is alway 50% of the magnet percentage zone. Then
         * the magnet trigger is the limit price +/- the magnet percentage.
         *
         * Both will be recorded on the orders type=LIMIT, and checked every
         * second.
         */
        for ($i = 0; $i < $this->position->total_limit_orders; $i++) {
            $quantity = api_format_quantity($marketOrderQuantity * (2 ** ($i + 1)), $this->position->ExchangeSymbol);
            $price = $this->getAveragePrice(($i + 1) * $percentageGap);

            if ($side['same'] == 'BUY') {
                $magnetActivationPrice = api_format_price($price * (1 + (0.5 * $magnetPercentage)), $this->position->exchangeSymbol);
                $magnetTriggerPrice = api_format_price($price * (1 + $magnetPercentage), $this->position->exchangeSymbol);
            } else { // SELL order
                $magnetActivationPrice = api_format_price($price * (1 - (0.5 * $magnetPercentage)), $this->position->exchangeSymbol);
                $magnetTriggerPrice = api_format_price($price * (1 - $magnetPercentage), $this->position->exchangeSymbol);
            }

            Order::create([
                'position_id' => $this->position->id,
                'type' => 'LIMIT',
                'side' => $side['same'],
                'price' => $price,
                'quantity' => $quantity,
                'magnet_activation_price' => $magnetActivationPrice,
                'magnet_trigger_price' => $magnetTriggerPrice,
                'magnet_status' => 'standby',
            ]);
        }

        // Create the market order.
        Order::create([
            'position_id' => $this->position->id,
            'type' => 'MARKET',
            'side' => $side['same'],
            'quantity' => $marketOrderQuantity,
        ]);

        // info("[DispatchPositionOrdersJob] - MARKET order quantity: {$marketOrderQuantity}");

        // Create the profit order.
        Order::create([
            'position_id' => $this->position->id,
            'type' => 'PROFIT',
            'side' => $side['opposite'],
        ]);

        // Dispatch all orders to be created.
        $this->dispatchOrders();

        return $this->position;
    }

    protected function dispatchOrders()
    {
        $blockUuid = (string) Str::uuid();

        $this->position->orders->each(function ($order) use ($blockUuid) {
            // info('[DispatchPositionOrdersJob] - Dispatching order ID '.$order->id.' ('.$order->type.')');

            CoreJobQueue::create([
                'class' => PlaceOrderJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $order->id,
                ],
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);
        });

        CoreJobQueue::create([
            'class' => ValidatePositionOpeningJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
    }

    protected function getAveragePrice(float $percentage): float
    {
        $change = $this->markPrice * ($percentage / 100);

        // For SHORT, the price increases. For LONG, it decreases.
        $newPrice = $this->position->direction == 'LONG'
        ? $this->markPrice - $change
        : $this->markPrice + $change;

        return api_format_price($newPrice, $this->position->exchangeSymbol);
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
