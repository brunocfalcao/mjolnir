<?php

namespace Nidavellir\Mjolnir\Observers;

use Nidavellir\Mjolnir\Jobs\Apiable\Order\CreateOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class OrderApiObserver
{
    public function created(Order $order): void
    {
        info('[OrderApiObserver] - Creating '.$order->type.' order for token '.$order->position->exchangeSymbol->symbol->token) . ', position ID: ' . $order->position->id . ', account from ' . $order->position->account->user->name;
        /*
        CoreJobQueue::create([
            'class' => CreateOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $order->id,
            ]
        ]);
        */
    }
}
