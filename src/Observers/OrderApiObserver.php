<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CreateOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class OrderApiObserver
{
    public function creating(Order $order): void
    {
        $order->uuid = (string) Str::uuid();
    }

    public function created(Order $order): void
    {
        $order->load([
            'position.exchangeSymbol.symbol',
            'position.account.user',
        ]);

        info('[OrderApiObserver] - Creating '.$order->type.' order ID '.$order->id.', position ID '.$order->position->id.' ('.$order->position->account->user->name.'), for token '.$order->position->exchangeSymbol->symbol->token).', position ID: '.$order->position->id.', account from '.$order->position->account->user->name;

        CoreJobQueue::create([
            'class' => CreateOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $order->id,
            ],
        ]);
    }
}
