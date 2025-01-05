<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
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

        // Place order
        $result = $order->apiPlace();

        // Dump the order information
        $this->line(print_r($result, true));

        return 0;
    }
}
