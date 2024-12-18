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

        // Max retries reached?
        if ($this->coreJobQueue->retries == $this->retries + 1) {
            throw new \Exception('CoreJobQueue max retries reached');
        }

        // Are we already polling limited?
        if ($this->isPollingLimited()) {
            $this->coreJobQueue->updateToRetry($this->rateLimiter->workerServerBackoffSeconds());

            return;
        }

        try {
            // Return result, to be saved in the core job queue instance.
            return $this->computeApiable();
        } catch (RequestException $e) {

            /**
             * There are different types of api exceptions. It's complicated.
             *
             * 1. A rate limit exception (forbidden or rate limited).
             * System will apply the rate limit on the rate limit table, and
             * will backoff the core job queue some seconds.
             *
             * 2. An ignorable exception.
             * System will just completely ignore the exception and treat it
             * as if the core job queue and the api call were perfectly fine.
             *
             * 3. A graceful retriable session (like HTTP 503) so this is just
             * a server backoff duration, and the system will retry again.
             *
             * 4. A real request exception that should be cascaded to the parent
             * class.
             */

            // Is the Request Exception, a rate limit/forbidden exception?
            $isNowLimited = $this->shouldLimitNow($e);
            if ($isNowLimited) {
                // Rate Limited?
                if ($this->rateLimiter->isRateLimited()) {
                    return $this->updateToRetry($this->rateLimiter->rateLimitbackoffSeconds());
                }

                // Forbidden?
                if ($this->rateLimiter->isForbidden()) {
                    return $this->updateToRetry($this->coreJobQueue->$workerServerBackoffSeconds);
                }
            }

            throw $e;
            $this->coreJobQueue->updateToCompleted();
            $this->coreJobQueue->finalizeDuration();
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
        $isNowLimited = $this->rateLimiter->shouldLimitNow($exception);

        if ($isNowLimited) {
            /**
             * Allows the core job queue instance to be placed back to pending, but there will be a
             * backoff to all worker servers to pick this job again. This will (hopefully) allow
             * other worker server to pick the job. In case it's gracefully retry, then it's
             * okay too, since normally it will be a question of seconds before any worker
             * server to pick the job again.
             */
            $this->coreJobQueue->updateToRetry($this->rateLimiter->workerServerBackoffSeconds());

            return true;
        }

        return false;
    }

    abstract public function computeApiable();
}
