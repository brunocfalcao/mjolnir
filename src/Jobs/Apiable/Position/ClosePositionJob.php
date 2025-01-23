<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\SyncOrderJob;

class ClosePositionJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

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
        $this->position->apiClose();

        // Verify if the profit order was expired.
        $this->position->load('orders');

        $profitOrder = $this->position->orders->firstWhere('type', 'PROFIT');

        if ($profitOrder->status == 'EXPIRED' && $profitOrder->status == 'CANCELLED') {
            $this->position->update([
                'error_message' => 'Profit order was expired or cancelled, no PnL calculated. Maybe it was a manual close?',
                'closing_price' => $profitOrder->price,
            ]);
        }

        // Any order without an exchange_order_id will be marked as not synced.
        $this->position->orders()->whereNull('orders.exchange_order_id')->update(['status' => 'NOT-SYNCED']);

        // Last sync orders.
        foreach ($this->position
            ->orders
            ->where('type', '<>', 'MARKET')
            ->whereNotNull('exchange_order_id') as $order) {
            CoreJobQueue::create([
                'class' => SyncOrderJob::class,
                'queue' => 'orders',

                'arguments' => [
                    'orderId' => $order->id,
                ]
            ]);
        }
    }
}
