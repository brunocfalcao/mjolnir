<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreateMagnetOrder;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CancelOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class CreateMagnetOrderJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::with(['position'])->findOrFail($orderId);
        $this->position = $this->order->position;
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        CoreJobQueue::create([
            'class' => CancelOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $this->order->id,
            ],
        ]);

        CoreJobQueue::create([
            'class' => CreateAndPlaceMarketMagnetOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $this->position->id,
                'limitOrderId' => $this->order->id,
            ],
        ]);
    }
}
