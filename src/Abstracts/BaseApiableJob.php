<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;

abstract class BaseApiableJob extends BaseQueuableJob
{
    public ?BaseRateLimiter $rateLimiter;

    public ?BaseExceptionHandler $exceptionHandler;

    protected function compute()
    {
        $this->checkApiRequiredClasses();

        // Are we already polling limited?
        if ($this->isPollingLimited()) {
            info('(BaseApiableJob.isPollingLimited) ID: '.$this->coreJobQueue->id.' - true');
        } else {
            info('(BaseApiableJob.isPollingLimited) ID: '.$this->coreJobQueue->id.' - false');
        }
        if ($this->isPollingLimited()) {
            info('(BaseApiableJob.isPollingLimited) ID: '.$this->coreJobQueue->id);
            // Backoff job the same as the rate limit backoff seconds.
            $this->coreJobQueue->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());

            // Flag the parent class that all status were updated.
            $this->coreJobQueueStatusUpdated = true;

            return;
        }

        try {
            // Return result, to be saved in the core job queue instance.
            info('(BaseApiableJob.computeApiable) ID: '.$this->coreJobQueue->id);

            return $this->computeApiable();

            // All good.
        } catch (\Throwable $e) {
            info('(BaseApiableJob.Throwable) ID: '.$this->coreJobQueue->id);
            if ($e instanceof RequestException) {
                info('(BaseApiableJob.RequestException) ID: '.$this->coreJobQueue->id);
                // Check if it's a rate limit or forbidden exception.
                $isNowLimited = $this->rateLimiter->isNowLimited($e);

                if ($isNowLimited) {
                    info('(BaseApiableJob.RequestException.isNowLimited.updateToRetry) ID: '.$this->coreJobQueue->id);
                    // At least we backoff the work servers to pick the job again, we might get the same worker server.
                    $this->coreJobQueue->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());

                    // Flag the parent class that all status were updated.
                    $this->coreJobQueueStatusUpdated = true;

                    return;
                }
            }

            // Escalate to treat remaining exception types (ignorable, retriable, resolvable).
            throw $e;
        }
    }

    public function checkApiRequiredClasses()
    {
        if (! isset($this->rateLimiter)) {
            throw new \Exception('Rate Limiter class not instanciated on '.static::class);
        }

        if (! isset($this->exceptionHandler)) {
            throw new \Exception('Rate Limiter class not instanciated on '.static::class);
        }
    }

    public function isPollingLimited(): bool
    {
        return $this->rateLimiter->isRateLimited() || $this->rateLimiter->isForbidden();
    }

    abstract public function computeApiable();
}
