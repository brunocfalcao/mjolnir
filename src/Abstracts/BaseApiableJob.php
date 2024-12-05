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
                if ($this->rateLimiter->isNowRateLimited($this->result)) {
                    $seconds = $this->rateLimiter->throttle();
                    $this->coreJobQueue->updateToPending(now()->addSeconds($seconds));
                }

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
                info('Started running RequestException ['.$this->coreJobQueue->id.']');

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
        $retryAfter = $this->rateLimiter->isRateLimited();
        if ($retryAfter) {
            $this->coreJobQueue->updateToPending($retryAfter);

            return true;
        }

        // Is the worker server forbidden on this account?
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
            $retryAfter = $this->rateLimiter->throttle();
            $this->coreJobQueue->updateToPending($retryAfter);
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
