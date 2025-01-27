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
            ->active()
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            $openOrders = collect($account->apiQueryOpenOrders()->result);
            $exchangeStandbyOrders = $this->getStandbyOrders($openOrders);

            $dbStandbyOrders = $account->positions()
                ->opened()
                ->with(['orders' => function ($query) {
                    $query->active();
                }])
                ->get()
                ->pluck('orders')
                ->flatten();

            if ($exchangeStandbyOrders->count() != $dbStandbyOrders->count()) {
                User::admin()->get()->each(function ($user) use ($account, $exchangeStandbyOrders, $dbStandbyOrders) {
                    $user->pushover(
                        message: "Account ID {$account->id}: Exchange Standby Orders = {$exchangeStandbyOrders->count()}, DB Standby Orders = {$dbStandbyOrders->count()}",
                        title: 'Integrity Check failed - Total standby orders mismatch',
                        applicationKey: 'nidavellir_warnings'
                    );
                });
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
        })->values(); // Reindex the collection after filtering.
    }
}
