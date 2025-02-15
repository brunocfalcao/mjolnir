<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Processes\CreateMagnetOrder\CreateMagnetOrderLifecycleJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class AssessMagnetTriggerJob extends BaseQueuableJob
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

    public function compute()
    {
        $magnetTriggerOrder = $this->position->assessMagnetTrigger();



        if ($magnetTriggerOrder != null) {
            User::admin()->get()->each(function ($user) use ($magnetTriggerOrder) {
                $user->pushover(
                    message: "Starting CreateMagnetOrderLifecycleJob, Magnet Order ID: {$magnetTriggerOrder->id}",
                    title: 'Starting CreateMagnetOrderLifecycleJob',
                    applicationKey: 'nidavellir_warnings'
                );
            });

            // We have a position to trigger the magnet.
            CoreJobQueue::create([
                'class' => CreateMagnetOrderLifecycleJob::class,
                'queue' => 'orders',
                'arguments' => [
                    'orderId' => $magnetTriggerOrder->id,
                ],
            ]);
        }
    }
}
