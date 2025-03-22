<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Nidavellir\Mjolnir\Jobs\Processes\ClosePosition\ClosePositionLifecycleJob;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\User;

class RunIntegrityChecksCommand extends Command
{
    protected $signature = 'mjolnir:run-integrity-checks';

    protected $description = 'Run integrity checks, reports via pushover.';

    public function handle()
    {
        // I want to check on the core_job_queue (CoreJob) has delayed/not picked entries for more than 5 minutes.
        $notProcessedJobs = CoreJobQueue::where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('dispatch_after')
                    ->orWhere('dispatch_after', '<=', Carbon::now());
            })
            ->where('created_at', '<=', Carbon::now()->subMinutes(5))
            ->get();

        if ($notProcessedJobs->isNotEmpty()) {
            User::admin()->get()->each(function ($user) use ($notProcessedJobs) {
                $user->pushover(
                    message: 'There are Core Job Queue entries to be processed longer than 5 mins ago! E.g.: ID: '.$notProcessedJobs->first()->id,
                    title: 'Integrity Check failed - Delayed processing core job queue entries',
                    applicationKey: 'nidavellir_warnings'
                );
            });
        }

        // Verify if there is a laravel.log file, and if it was created less than 15 mins ago.
        $logPath = storage_path('logs/laravel.log');

        // Check if the file exists.
        if (! File::exists($logPath)) {
            $this->error('Log file does not exist.');

            return 1;
        }

        // Get the file's last modified time.
        $lastModified = File::lastModified($logPath);
        $fileTime = Carbon::createFromTimestamp($lastModified);

        // Compare with the current time.
        if ($fileTime->diffInMinutes(Carbon::now()) < 15) {
            User::admin()->get()->each(function ($user) {
                $user->pushover(
                    message: 'A laravel.log file was created earlier today',
                    title: 'Integrity Check failed - A laravel.log file was created',
                    applicationKey: 'nidavellir_warnings'
                );
            });
        }

        // Retrieve accounts where the user is a trader and is eligible for trading.
        $accounts = Account::whereHas('user', function ($query) {
            $query->where('is_trader', true);
        })->with('user')
            ->canTrade()
            ->get();

        // Loop over each eligible account to perform integrity checks.
        foreach ($accounts as $account) {
            // Collect open orders from the exchange.
            $openOrders = collect($account->apiQueryOpenOrders()->result);
            // Filter orders to get those with status NEW or PARTIALLY_FILLED.
            $exchangeStandbyOrders = $this->getStandbyOrders($openOrders);

            // Retrieve orders associated with active positions from the local database.
            $dbStandbyOrders = $account->positions()
                ->where('positions.status', 'active')
                ->with(['orders' => function ($query) {
                    $query->active();
                }])
                ->get()
                ->pluck('orders')
                ->flatten();

            // Check if the difference between exchange and database orders exceeds the threshold.
            if (abs($exchangeStandbyOrders->count() - $dbStandbyOrders->count()) > 8) {
                // Notify admin users about a mismatch in standby orders.
                User::admin()->get()->each(function ($user) use ($account, $exchangeStandbyOrders, $dbStandbyOrders) {
                    $user->pushover(
                        message: "Account ID {$account->id}: Exchange Standby Orders = {$exchangeStandbyOrders->count()}, DB Standby Orders = {$dbStandbyOrders->count()}. Please check!",
                        title: 'Integrity Check failed - Total standby orders mismatch',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }

            // Retrieve open positions from the exchange.
            $positions = $account->apiQueryPositions()->result;

            // Remove false positions (positionAmt = 0.0)
            $positions = array_filter($positions, function ($position) {
                return (float) $position['positionAmt'] != 0.0;
            });

            // Check if the number of positions exceeds the account's maximum allowed concurrent positions.
            if (count($positions) > $account->max_concurrent_trades && $account->max_concurrent_trades > 0) {
                // Notify admin users if the maximum concurrent positions are exceeded.
                User::admin()->get()->each(function ($user) use ($account, $positions) {
                    $user->pushover(
                        message: "Account ID {$account->id}: Max positions exceeded. Exchange opened positions: ".count($positions).', Max concurrent positions: '.$account->max_concurrent_trades.'. Please check!',
                        title: 'Integrity Check failed - Max concurrent positions exceeded',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
            }

            // Retrieve active positions along with their orders from the database.
            $openedPositions = $account->positions()
                ->with('orders')
                ->where('positions.status', 'active')
                ->get();

            // Check for active positions with a profit order that has an invalid status.
            foreach ($openedPositions as $openedPosition) {
                if ($openedPosition->orders
                    ->where('type', 'PROFIT')
                    ->whereNotIn('status', ['NEW', 'PARTIALLY_FILLED'])
                    ->isNotEmpty()
                ) {
                    // Retrieve the first profit order for the position.
                    $openedProfitOrder = $openedPosition->orders->firstWhere('type', 'PROFIT');
                    // Notify admin users about the invalid profit order status.
                    User::admin()->get()->each(function ($user) use ($openedPosition, $openedProfitOrder) {
                        $user->pushover(
                            message: "Active Position {$openedPosition->parsedTradingPair} ID {$openedPosition->id} with PROFIT order ID {$openedProfitOrder->id}, with status {$openedProfitOrder->status}. Please check!",
                            title: 'Integrity Check failed - Opened position with invalid profit order status',
                            applicationKey: 'nidavellir_warnings'
                        );
                    });
                }
            }

            // Verify the correctness of the WAP calculation for each active position.
            foreach ($openedPositions as $openedPosition) {
                // Check if the position has at least one FILLED order of type LIMIT or MARKET-MAGNET,
                // at least one PROFIT order with status NEW or PARTIALLY_FILLED, and WAP recalculation has not been triggered.
                if ($openedPosition->orders()
                    ->whereIn('type', ['LIMIT', 'MARKET-MAGNET'])
                    ->where('status', 'FILLED')
                    ->exists() &&
                $openedPosition->orders()
                    ->where('type', 'PROFIT')
                    ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
                    ->exists() &&
                $openedPosition->wap_triggered == false
                ) {
                    // Calculate the Weighted Average Price (WAP) for the position.
                    $wap = $openedPosition->calculateWAP();
                    // Retrieve the profit order for comparison.
                    $openedProfitOrder = $openedPosition->orders->firstWhere('type', 'PROFIT');
                    // Check if the calculated WAP differs from the profit order's price and quantity.
                    if ($wap['price'] != $openedProfitOrder->price) {
                        // Ensure the exchange symbol relationship is loaded.
                        $openedPosition->load('exchangeSymbol');

                        // Format the profit order and WAP values for clarity.
                        $orderPrice = api_format_price($openedProfitOrder->price, $openedPosition->exchangeSymbol);
                        $orderQuantity = api_format_quantity($openedProfitOrder->quantity, $openedPosition->exchangeSymbol);
                        $wapPrice = api_format_price($wap['price'], $openedPosition->exchangeSymbol);
                        $wapQuantity = api_format_quantity($wap['quantity'], $openedPosition->exchangeSymbol);
                        $tradingPair = $openedPosition->parsedTradingPair;

                        /*
                        User::admin()->get()->each(function ($user) use ($openedPosition) {
                            $user->pushover(
                                message: "Active Position {$openedPosition->parsedTradingPair} ID {$openedPosition->id} with wrong WAP calculated. Reseting position FILLED orders to NEW, for resyncing + WAP calculation",
                                title: 'Integrity Check failed - Active position with wrong WAP calculated. Resyncing orders.',
                                applicationKey: 'nidavellir_warnings'
                            );
                        });
                        */
                        User::admin()->get()->each(function ($user) use ($openedPosition, $orderPrice, $wapPrice) {
                            $user->pushover(
                                message: "Active Position {$openedPosition->parsedTradingPair} ID {$openedPosition->id} with wrong WAP calculated. Current: {$orderPrice}, should be: {$wapPrice}",
                                title: 'Integrity Check failed - Active position with wrong WAP calculated.',
                                applicationKey: 'nidavellir_warnings'
                            );
                        });

                        /*
                        $openedPosition->orders->where('status', 'FILLED')->each->updateQuietly(
                            ['status' => 'NEW',
                                'skip_observer' => false]
                        );

                        $openedPosition->syncOrders();
                        */
                    }
                }
            }

            /**
             * INTEGRITY CHECK
             * Check if we have opened positions on our local DB that don't match
             * opened positions on the exchange. For instance, if we manually
             * closed a position on the exchange, and we have a position with
             * that exchange symbol on an active status (status=active only)
             * we should trigger the ClosePosition lifecycle.
             */
            $dataMapper = new ApiDataMapperProxy($account->apiSystem->canonical);

            foreach ($openedPositions as $openedPosition) {
                if (! array_key_exists($openedPosition->parsedTradingPair, $positions)) {
                    /**
                     * We have an active position on the account that doesn't have
                     * an active position on the exchange. Close position, just in case.
                     */
                    User::admin()->get()->each(function ($user) use ($openedPosition) {
                        $user->pushover(
                            message: "Position {$openedPosition->parsedTradingPair} is locally active and it is not on the exchange. Closing it",
                            title: 'Integrity Check failed - Active local position that is not findable on exchange',
                            applicationKey: 'nidavellir_warnings'
                        );
                    });

                    CoreJobQueue::create([
                        'class' => ClosePositionLifecycleJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $openedPosition->id,
                        ],
                    ]);
                }
            }
        }
    }

    /**
     * Get only orders with status NEW or PARTIALLY_FILLED.
     */
    protected function getStandbyOrders(Collection $orders): Collection
    {
        return $orders->filter(function ($order) {
            return in_array($order['status'], ['NEW', 'PARTIALLY_FILLED']);
        })->values();
    }
}
