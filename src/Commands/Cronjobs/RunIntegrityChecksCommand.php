<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\User;
use Illuminate\Support\Collection;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CalculateWAPAndAdjustProfitOrderJob;

class RunIntegrityChecksCommand extends Command
{
    protected $signature = 'mjolnir:run-integrity-checks';

    protected $description = 'Run integrity checks, reports via pushover';

    public function handle()
    {
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true);
        })->with('user')
            ->canTrade()
            ->get();

        /**
         * Most of the integrity checks are calculated on accounts that
         * can trade.
         */
        foreach ($accounts as $account) {
            $openOrders = collect($account->apiQueryOpenOrders()->result);
            $exchangeStandbyOrders = $this->getStandbyOrders($openOrders);

            $dbStandbyOrders = $account->positions()
                ->where('positions.status', 'active')
                ->with(['orders' => function ($query) {
                    $query->active();
                }])
                ->get()
                ->pluck('orders')
                ->flatten();

            /**
             * INTEGRITY CHECK
             *
             * How many total orders to we have on the exchange vs how many
             * do we have on the local database? If the difference is more
             * than X orders, it will trigger a notification.
             */
            if (abs($exchangeStandbyOrders->count() - $dbStandbyOrders->count()) > 8) {
                User::admin()->get()->each(function ($user) use ($account, $exchangeStandbyOrders, $dbStandbyOrders) {
                    $user->pushover(
                        message: "Account ID {$account->id}: Exchange Standby Orders = {$exchangeStandbyOrders->count()}, DB Standby Orders = {$dbStandbyOrders->count()}",
                        title: 'Integrity Check failed - Total standby orders mismatch',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }

            /**
             * INTEGRITY CHECK
             *
             * Do we have more positions on the exchange than the maximum concurrent positions?
             */
            $positions = $account->apiQueryPositions()->result;

            if (count($positions) > $account->max_concurrent_trades && $account->max_concurrent_trades > 0) {
                User::admin()->get()->each(function ($user) use ($account, $positions) {
                    $user->pushover(
                        message: "Account ID {$account->id}: Max positions exceeded. Exchange opened positions: ".count($positions).', Max concurrent positions: '.$account->max_concurrent_trades,
                        title: 'Integrity Check failed - Max concurrent positions exceeded',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }

            /**
             * INTEGRITY CHECK
             *
             * Verify if we have open positions with PROFIT = FILLED or CANCELLED. If that's the case
             * we need to ALERT for this error so the admin can take action.
             */
            $openedPositions = $account->positions()->with('orders')->where('positions.status', 'active')->get();

            foreach ($openedPositions as $openedPosition) {
                if ($openedPosition->orders
                    ->where('type', 'PROFIT')
                    ->whereNotIn('status', ['NEW', 'PARTIALLY_FILLED'])
                    ->isNotEmpty()) {
                    $openedProfitOrder = $openedPosition->orders->firstWhere('type', 'PROFIT');

                    User::admin()->get()->each(function ($user) use ($openedPosition, $openedProfitOrder) {
                        $user->pushover(
                            message: "Active Position {$openedPosition->parsedTradingPair} ID {$openedPosition->id} with PROFIT order ID {$openedProfitOrder->id}, with status {$openedProfitOrder->status}. Please check!",
                            title: 'Integrity Check failed - Opened position with invalid position',
                            applicationKey: 'nidavellir_warnings'
                        );
                    });
                }
            }

            /**
             * INTEGRITY CHECK
             *
             * Verify if the WAP is well calculated for each of the
             * active positions that have at least one limit order filled.
             */
            foreach ($openedPositions as $openedPosition) {
                // Check if the position has at least one FILLED order of the specified types
                if ($openedPosition->orders()
                    ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
                    ->where('status', 'FILLED')
                    ->exists()) {

                    /**
                     * Lets calculate the WAP, and then verify if it's the same
                     * price and quantity as the profit order. If not, we
                     * recalculate the WAP.
                     */

                    /**
                        return [
                            'resync' => $resync,
                            'error' => $error,
                            'quantity' => api_format_quantity($totalQuantity, $this->exchangeSymbol),
                            'price' => api_format_price($wapPrice, $this->exchangeSymbol),
                        ];
                     */
                    $wap = $openedPosition->calculateWAP();
                    $openedProfitOrder = $openedPosition->orders->firstWhere('type', 'PROFIT');
                    if ($wap['quantity'] != $openedProfitOrder->quantity ||
                       $wap['price'] != $openedProfitOrder->price) {
                        $openedPosition->loadMissing('exchangeSymbol');

                        // Format values (WAP and Exchange Symbol);
                        $orderPrice = api_format_price($openedProfitOrder->price, $openedPosition->exchangeSymbol);
                        $orderQuantity = api_format_quantity($openedProfitOrder->quantity, $openedPosition->exchangeSymbol);
                        $wapPrice = api_format_price($wap['price'], $openedPosition->exchangeSymbol);
                        $wapQuantity = api_format_quantity($wap['quantity'], $openedPosition->exchangeSymbol);
                        $tradingPair = $openedPosition->parsedTradingPair;

                        // Something happened, the WAP is wrongly calculated.
                        User::admin()->get()->each(function ($user) use ($tradingPair, $orderPrice, $orderQuantity, $wapPrice, $wapQuantity) {
                            $user->pushover(
                                message: "Position {$tradingPair} with wrong WAP calculation: Current: {$orderPrice}/{$orderQuantity} vs correct: {$wapPrice}/{$wapQuantity}. Triggering recalculation ...",
                                title: "{$tradingPair} - Integrity check failed - WAP wrongly calculated",
                                applicationKey: 'nidavellir_warnings'
                            );
                        });

                        CoreJobQueue::create([
                            'class' => CalculateWAPAndAdjustProfitOrderJob::class,
                            'queue' => 'orders',
                            'arguments' => [
                                'orderId' => $openedProfitOrder->id,
                                'originalPrice' => $openedProfitOrder->price,
                                'originalQuantity' => $openedProfitOrder->quantity,
                            ]
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Get only orders with status 'NEW' or 'PARTIALLY_FILLED'.
     */
    protected function getStandbyOrders(Collection $orders): Collection
    {
        return $orders->filter(function ($order) {
            return in_array($order['status'], ['NEW', 'PARTIALLY_FILLED']);
        })->values();
    }
}
