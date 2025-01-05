<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;

class SyncAllOrdersJob extends BaseApiableJob
{
    public Account $account;
    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        // Initialize the account and related properties
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        // Fetch all open positions for the account and process them
        $positions = $this->getOpenPositions();

        foreach ($positions as $position) {
            $this->syncOrdersForPosition($position);
        }
    }

    /**
     * Fetch all open positions for the current account with their orders eager loaded.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getOpenPositions()
    {
        return Position::opened()
            ->where('account_id', $this->account->id)
            ->with('orders') // Eager load orders to prevent N+1 queries
            ->get();
    }

    /**
     * Sync all valid orders for the given position.
     *
     * @param Position $position
     */
    private function syncOrdersForPosition(Position $position)
    {
        foreach ($position->orders->whereNotNull('exchange_order_id') as $order) {
            try {
                $order->apiSync();
            } catch (\Throwable $e) {
                // Log errors for failed order syncing
                \Log::error("Failed to sync order ID {$order->id} for position ID {$position->id}: {$e->getMessage()}");
            }
        }
    }
}
