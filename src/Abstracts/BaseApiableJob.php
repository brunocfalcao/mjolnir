<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\RateLimit;

abstract class BaseApiableJob extends BaseQueuableJob
{
    public ?BaseRateLimiter $rateLimiter;

    public ?BaseApiExceptionHandler $exceptionHandler;

    protected function compute()
    {
        $this->checkApiRequiredClasses();

        // Are we already polling limited?
        if ($this->isPollingLimited()) {
            // Put the job back to be processed again.
            $this->updateToReseted();

            return;
        }

        try {
            // Verify if there is a return result, if so, assign it to $result.
            $aux = $this->computeApiable();
            if ($aux) {
                $this->result = $aux;
            }
            unset($aux);

            // Result is a Guzzle Response.
            if ($this->result && $this->result instanceof Response) {
                $this->rateLimiter->isNowRateLimited($this->result)
                     && $this->rateLimiter->throttle();

                $this->rateLimiter->isNowForbidden($this->result)
                    && $this->rateLimiter->forbid();

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
                    // Get the job back on the queue for other worker server.
                    // All reset logic was treated inside the previous method.
                    $this->coreJobQueue->updateToReseted();

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
        $rateLimit = RateLimit::where('account_id', $this->rateLimiter->account->id)
            ->where('api_system_id', $this->rateLimiter->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        if ($rateLimit && $rateLimit->retry_after?->isFuture()) {
            return true;
        }

        $forbidden = RateLimit::where('api_system_id', $this->rateLimiter->account->api_system_id)
            ->where('hostname', gethostname())
            ->where('retry_after', null)
            ->first();

        return $forbidden != null;
    }

    public function handleRateLimitedBaseException(RequestException $exception)
    {
        $isNowPollingLimited = false;

        if ($this->rateLimiter->isNowRateLimited($exception)) {
            $this->rateLimiter->throttle();
            $isNowPollingLimited = true;
        }

        if ($this->rateLimiter->isNowForbidden($exception)) {
            $this->rateLimiter->forbid();
            $isNowPollingLimited = true;
        }

        return $isNowPollingLimited || false;
    }

    abstract public function computeApiable();
}
