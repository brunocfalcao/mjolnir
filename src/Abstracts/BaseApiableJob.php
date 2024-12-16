<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\CoreJobQueue;

abstract class BaseApiableJob extends BaseQueuableJob
{
    public ?BaseRateLimiter $rateLimiter;

    public ?BaseApiExceptionHandler $exceptionHandler;

    protected function compute()
    {
        $this->checkApiRequiredClasses();

        // Max retries reached?
        if ($this->coreJobQueue->retries == $this->retries + 1) {
            throw new \Exception('CoreJobQueue max retries reached');
        }

        // Are we already polling limited?
        if ($this->isPollingLimited()) {
            $this->coreJobQueue->updateToPending($this->rateLimiter->workerServerBackoffSeconds());

            return;
        }

        try {
            // Verify if there is a return result, if so, assign it to $result.
            $this->result = $this->computeApiable();

            // Result is a Guzzle Response.
            if ($this->result) {
                if ($this->result instanceof Response) {
                    $isPolledLimited = $this->rateLimiter->shouldLimitNow($this->result);

                    if ($this->rateLimiter->isNowRateLimited($this->result)) {
                        $this->rateLimiter->throttle();
                    }

                    if ($this->rateLimiter->isNowForbidden($this->result)) {
                        $this->rateLimiter->forbid();
                    }

                    if ($isPolledLimited) {
                        /**
                         * Allows the core job queue instance to be placed back to pending, but there will be a
                         * backoff to all worker servers to pick this job again. This will (hopefully) allow
                         * other worker server to pick the job. In case it's gracefully retry, then it's
                         * okay too, since normally it will be a question of seconds before any worker
                         * server to pick the job again.
                         */
                        $this->coreJobQueue->updateToPending($this->rateLimiter->workerServerBackoffSeconds());
                    }
                }

                return $this->result;
            }
        } catch (RequestException $e) {

            /**
             * As soon as there is an exception, we need to identify what type
             * of exception are we having. If it's a RequestException then it
             * might be related with Rate Limit or Forbidden causes. If that's
             * the case, we can release base the job into the CoreJobQueue for
             * re-executing by another worker server.
             *
             * If not, we then run the default exception workflow.
             */
            if ($e instanceof RequestException) {
                // Is the Request Exception, a rate limit/forbidden exception?
                $isNowRateLimitedOrForbidden = $this->handleRateLimitedBaseException($e);
                if ($isNowRateLimitedOrForbidden) {
                    return;
                }

                // Do we have a local ignoreRequestException method?
                $ignoreException = method_exists($this, 'ignoreRequestException') && $this->ignoreRequestException($e);

                // Do we have an exception handler ignoreRequestException?
                if (! $ignoreException && isset($this->exceptionHandler)) {
                    $ignoreException = $this->exceptionHandler->ignoreRequestException($e);
                }

                if (! $ignoreException) {
                    // Cascade the exception to the BaseQueueableJob class.
                    throw $e;
                }
            }

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

    public function handleRateLimitedBaseException(RequestException $exception)
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
            $this->coreJobQueue->updateToPending($this->rateLimiter->workerServerBackoffSeconds());

            return true;
        }

        return false;
    }

    abstract public function computeApiable();
}
