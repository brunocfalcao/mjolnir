<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Order;

class OrderQueryCommand extends Command
{
    protected $signature = 'excalibur:order-query
                        {--exchangeOrderId= : The exchange order ID to query}
                        {--id= : The database ID of the order to query}';

    protected $description = 'Queries an order by either exchange order id or database id';

    public function handle()
    {
        $exchangeOrderId = $this->option('exchangeOrderId');
        $id = $this->option('id');

        if ($exchangeOrderId) {
            $order = Order::firstWhere('exchange_order_id', $exchangeOrderId);
        } elseif ($id) {
            $order = Order::find($id);
        } else {
            $this->error('You must provide either --exchangeOrderId or --id');

            return 1; // Exit with an error code
        }

        if (! $order) {
            $this->error('Order not found.');

            return 1; // Exit with an error code
        }

        dd($order->apiQuery());
    }
}
