<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\PlaceOrderJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Order;

class PlaceOrderCommand extends Command
{
    protected $signature = 'debug:place-order {order_id}';

    protected $description = 'Places an order from an active position';

    public function handle()
    {
        // Get the argument
        $orderId = $this->argument('order_id');

        // Query the order by the exchange_order_id column
        $order = Order::findOrFail($orderId);

        CoreJobQueue::create([
            'class' => PlaceOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $order->id,
            ],
        ]);

        // Place order
        // $apiResponse = $order->apiPlace();

        // Dump the order information
        // $this->line(print_r($apiResponse->result, true));

        return 0;
    }
}
