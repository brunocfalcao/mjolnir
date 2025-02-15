<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class CreateAndPlaceMarketMagnetOrderJob extends BaseApiableJob
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

    public function computeApiable()
    {
        $dataMapper = new ApiDataMapperProxy($this->account->apiSystem->canonical);

        /**
         * We need to create a new order type=MARKET-MAGNET. This is basically
         * a market order but will be treated as a limit order. The quantity
         * is the same as the limit order passed as argument.
         */
        $limitMagnetOrder = Order::create([
            'position_id' => $this->order->position->id,
            'type' => 'MARKET-MAGNET',
            'status' => 'NEW',
            'side' => $this->order->side,
            'quantity' => $this->order->quantity,
        ]);

        $limitMagnetOrder->apiPlace();
        $limitMagnetOrder->apiSync();

        // De-magnetize original limit order.
        $this->order->withoutEvents(function () {
            $this->order->update(['magnet_status' => 'triggered']);
        });

        User::admin()->get()->each(function ($user) {
            $user->pushover(
                message: "Limit MAGNET order was filled for position {$this->order->position->parsedTradingPair}",
                title: "Limit Magnet order placed ({$this->order->position->parsedTradingPair}) successfully",
                applicationKey: 'nidavellir_orders'
            );
        });
    }

    public function resolveException(\Throwable $e)
    {
        User::admin()->get()->each(function ($user) {
            $user->pushover(
                message: 'Error creating the magnet order, so we are replacing back the original LIMIT order',
                title: "Error placing Magnet order for position {$this->order->position->parsedTradingPair}",
                applicationKey: 'nidavellir_orders'
            );
        });

        /**
         * If we cannot place the market magnet, then we need to
         * put back the limit order where it was, with the same
         * quantity and price.
         */
        $newLimitOrder = Order::create([
            'position_id' => $this->order->position->id,
            'type' => 'LIMIT',
            'status' => 'NEW',
            'side' => $this->order->side,
            'price' => $this->order->price,
            'quantity' => $this->order->quantity,
        ]);

        $newLimitOrder->apiPlace();
    }
}
