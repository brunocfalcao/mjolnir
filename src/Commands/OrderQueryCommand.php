<?php

namespace Nidavellir\Mjolnir\Commands;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;

class OrderQueryCommand extends Command
{
    protected $signature = 'excalibur:order-query {exchangeOrderId}';

    protected $description = 'Queries an order by exchange order id';

    public function handle()
    {
        $exchangeOrderId = $this->argument('exchangeOrderId');

        $order = Order::firstWhere('exchange_order_id', $exchangeOrderId);

        $dataMapper = new ApiDataMapperProxy($order->position->account->apiSystem->canonical);

        $response = $order->apiQuery();

        dd($dataMapper->resolveQueryOrderData($response));
    }
}
