<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\UpdateWAP;

use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Jobs\Apiable\Order\CancelOrderJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class UpdateWAPLifecycleJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;
        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        // Verify if there is already a wap triggered. Can be something very fast to happen.
        if ($this->position->wap_triggered) {
            return;
        }

        $blockUuid = (string) Str::uuid();

        $profitOrder = $this->position->profitOrder();

        if (is_null($profitOrder->exchange_order_id)) {
            User::admin()->get()->each(function ($user) {
                $user->pushover(
                    message: "{$this->position->parsedTradingPair} position doesn't have profit order synced! Please check!",
                    title: 'Integrity failed - Profit order is not created/synced, there is no exchange_order_id',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            return;
        }

        // Calculate new WAP.
        $wap = $this->position->calculateWAP();

        $profitOrder->logs()->create([
            'action_canonical' => 'wap-calculation-completed',
            'parameters_array' => ['wap-price' => $wap['price']],
            'description' => 'The new WAP price was calculated on the UpdateLifecycleJob::class',
        ]);

        if (array_key_exists('resync', $wap) && $wap['resync'] == true) {
            // Something happened and we need to resync the orders. Then we can try again the core job.
            User::admin()->get()->each(function ($user) use ($wap) {
                $user->pushover(
                    message: "WAP calculation for ({$this->position->parsedTradingPair} Position ID: {$this->position->id}) orders need to be resynced and will be retried. Error: {$wap['error']}",
                    title: 'WAP calculation orders need to be resynced and retried later',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            $this->position->load('orders');
            foreach ($this->position->orders as $order) {
                $order->apiSync();
            }

            $this->coreJobQueue->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());

            return;
        }

        // Inform the order observer not to put the PROFIT order back on its original values.
        $this->position->update(['wap_triggered' => true]);

        // Cancel current profit order.
        CoreJobQueue::create([
            'class' => CancelOrderJob::class,
            'queue' => 'orders',
            'arguments' => [
                'orderId' => $profitOrder->id,
            ],
            'index' => 1,
            'block_uuid' => $blockUuid,
        ]);

        // Complete WAP process (place new order with new weighted average price).
        CoreJobQueue::create([
            'class' => ResettleProfitOrderFromWAPJob::class,
            'queue' => 'orders',
            'arguments' => [
                'positionId' => $this->position->id,
                'olderProfitOrderId' => $profitOrder->id,
                'newPrice' => $wap['price'],

            ],
            'index' => 2,
            'block_uuid' => $blockUuid,
        ]);
    }
}
