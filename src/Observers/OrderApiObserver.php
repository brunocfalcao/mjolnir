<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CreateOrderJob;

class OrderApiObserver
{
    public function created(Order $order): void
    {
        CoreJobQueue::create([
            'class' => CreateOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $order->id,
            ]
        ]);
    }
}
