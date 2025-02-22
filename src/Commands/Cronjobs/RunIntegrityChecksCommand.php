<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\User;

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
             * Verify if we have open positions with PROFIT = FILLED or CANCELLED.
             */
            $openedPositions = $account->positions()->where('positions.status', 'active')->get();

            foreach ($openedPositions as $openedPosition) {
                info('Checking position ' . $openedPosition->id);

                if ($openedPosition->orders()
                    ->where('type', 'PROFIT')
                    ->where('status', '<>', 'NEW')
                    ->exists()) {
                    User::admin()->get()->each(function ($user) use ($account, $openedPosition) {
                        $user->pushover(
                            message: "Account ID {$account->id}, Opened Position {$openedPosition->parsedTradingPair}, ID {$openedPosition->id} with PROFIT order status {$openedPosition->status}. Please check!",
                            title: 'Integrity Check failed - Opened position with invalid position',
                            applicationKey: 'nidavellir_warnings'
                        );
                    });
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
