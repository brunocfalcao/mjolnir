<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\RollbackPosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CancelOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class ReplaceProfitOrderLifecycleJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $previousOrder;

    public float $newPrice;

    public function __construct(int $previousOrderId, float $newPrice)
    {
        $this->previousOrder = Order::with(['position'])->findOrFail($previousOrderId);
        $this->newPrice = $newPrice;

        $this->position = $this->previousOrder->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $blockUuid = (string) Str::uuid();

        // Cancel current profit order.
        CoreJobQueue::create([
            'class' => CancelOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $this->previousOrder->id,
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        // Lets create a new profit order, since the previous one was cancelled.
        $newProfitOrder = Order::create([
            'position_id' => $this->previousOrder->position_id,
            'type' => 'PROFIT',
            'status' => 'NEW',
            'side' => $this->previousOrder->side,
            'quantity' => 0, // It's a close position order.
            'price' => $this->newPrice,
        ]);

        // Finally place the order.
        CoreJobQueue::create([
            'class' => PlaceOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $newProfitOrder->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
    }

    public function resolveException(\Throwable $e) {}
}
