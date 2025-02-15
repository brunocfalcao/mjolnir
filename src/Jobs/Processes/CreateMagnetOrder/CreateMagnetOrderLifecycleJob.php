<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreateMagnetOrder;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CancelOrderJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Position\CreateAndPlaceMarketMagnetOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

class CreateMagnetOrderLifecycleJob extends BaseQueuableJob
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
        $blockUuid = (string) Str::uuid();

        CoreJobQueue::create([
            'class' => CancelOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $this->order->id,
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        /*
        CoreJobQueue::create([
            'class' => CreateAndPlaceMarketMagnetOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $this->order->id,
            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
        */
    }
}
