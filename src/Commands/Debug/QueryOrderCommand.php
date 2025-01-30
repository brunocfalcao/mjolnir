<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Order;

class QueryOrderCommand extends Command
{
    protected $signature = 'debug:query-order {exchange_order_id}';

    protected $description = 'Queries an order and dumps its information';

    public function handle()
    {
        // Get the argument
        $exchangeOrderId = $this->argument('exchange_order_id');

        // Query the order by the exchange_order_id column
        $order = Order::where('exchange_order_id', $exchangeOrderId)->firstOrFail();

        $result = $order->apiQuery()->result;

        // Dump the order information
        $this->line(print_r($result, true));

        return 0;
    }
}
