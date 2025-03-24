<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class FetchAndStoreIndicatorsJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public Position $position;

    public string $indicatorIds;

    public function __construct(int $positionId, string $indicatorIds)
    {
        $this->indicatorIds = $indicatorIds;
        $this->position = Position::findOrFail($positionId);
        $this->account = $this->position->account;
        $this->apiSystem = $this->account->apiSystem;

        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)->withAccount($this->account);
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function computeApiable()
    {
        /**
         * Lets get all the indicators Ids, and store them into the
         * indicators history table.
         */
    }

    public function resolveException(\Throwable $e)
    {
        User::admin()->get()->each(function ($user) use ($e) {
            $user->pushover(
                message: "Error canceling order with ID {$this->order->id}. Error: ".$e->getMessage(),
                title: 'Error canceling order',
                applicationKey: 'nidavellir_errors'
            );
        });
    }
}
