<?php

namespace Nidavellir\Mjolnir\Jobs\Apiable\Position;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\User;

class AssessMagnetActivationJob extends BaseQueuableJob
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
        $magnetOrder = $this->position->assessMagnetActivation();

        /*
        if ($magnetOrder) {
            User::admin()->get()->each(function ($user) use ($magnetOrder) {
                $user->pushover(
                    message: "Magnet ACTIVATED for position {$this->position->parsedTradingPair} ID: {$this->position->id}, Order ID {$magnetOrder->id}, at price {$magnetOrder->magnet_activation_price}",
                    title: "Magnet ACTIVATED for position {$this->position->parsedTradingPair}",
                    applicationKey: 'nidavellir_warnings'
                );
            });
        }
        */
    }
}
