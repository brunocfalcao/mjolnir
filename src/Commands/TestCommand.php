<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Order;

class TestCommand extends Command
{
    protected $signature = 'debug:test';

    protected $description = 'Testing stuff';

    public function handle()
    {
        $order = Order::find(6); // Assuming you have the order instance

        if ($order) {
            $clonedOrder = $order->replicate(); // Clone the current instance
            $clonedOrder->created_at = now(); // Set a new creation timestamp
            $clonedOrder->updated_at = now(); // Optionally set an updated timestamp
            $clonedOrder->save(); // Save the cloned order to the database
        }

        return 0;
    }
}
