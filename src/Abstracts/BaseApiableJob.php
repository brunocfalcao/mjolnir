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
            // At least we backoff the core job queue entry some seconds too.
            $this->coreJobQueue->updateToRetry($this->rateLimiter->$this->rateLimitbackoffSeconds());

            return;
        }

        try {
            // Return result, to be saved in the core job queue instance.
            return $this->computeApiable();
        } catch (\Throwable $e) {
            if ($e instanceof RequestException) {
                // Check if it's a rate limit or forbidden exception.
                $isNowLimited = $this->rateLimiter->isNowLimited($e);
                if ($isNowLimited) {
                    // At least we backoff the work servers to pick the job again, we might get the same worker server.
                    return $this->coreJobQueue->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());
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

    public function verifyRateLimitedBaseException(RequestException $exception)
    {
        $isNowLimited = $this->rateLimiter->isNowLimited($exception);

        if ($isNowLimited) {
            /**
             * Allows the core job queue instance to be placed back to pending, but there will be a
             * backoff to all worker servers to pick this job again. This will (hopefully) allow
             * other worker server to pick the job. In case it's gracefully retry, then it's
             * okay too, since normally it will be a question of seconds before any worker
             * server to pick the job again.
             */
            $this->coreJobQueue->updateToRetry($this->workerServerBackoffSeconds);

            return true;
        }

        return false;
    }

    abstract public function computeApiable();
}
