<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\RateLimit;
use Psr\Http\Message\ResponseInterface;
use Nidavellir\Thor\Models\CoreJobQueue;
use GuzzleHttp\Exception\RequestException;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    public BaseRateLimiter $rateLimiter;

    public BaseApiExceptionHandler $exceptionHandler;

    public function handle()
    {
        // Check if we have a $this->type = 'api'. If so, then we are api-based.
        if (property_exists($this, 'type') && $this->type == 'api') {
            // Requirements check first.
            $this->checkApiRequiredClasses();

            // Are we already polling limited?
            if ($this->isPollingLimited()) {
                // Put the job back to be processed again.
                $this->updateToReseted();
                return;
            }
        }

        /**
         * The refresh method will be triggered if a job is being retried.
         * This allows us, for instance, to re-run a specific computation
         * on the child job to refresh timestamps, variables, information.
         *
         * This method is only called after a first execution of the job.
         */
        if (method_exists($this, 'refresh') && $this->coreJobQueue->canBeRefreshed()) {
            $this->refresh();
        }

        try {
            $this->coreJobQueue->updateToRunning();
            $this->coreJobQueue->startDuration();

            $this->computeAndStoreResult();

            // Result is a Guzzle Response.
            if ($this->result && $this->result instanceof Response) {
                $this->rateLimiter->isNowRateLimited($this->result)
                     && $this->rateLimiter->throttle();

                $this->rateLimiter->isNowForbidden($this->result)
                    && $this->rateLimiter->forbid();

                return $this->result;
            }

            $this->coreJobQueue->updateToCompleted();
            $this->coreJobQueue->finalizeDuration();
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
                $wasRateLimitedSomehow = $this->handleRateLimitedBaseException();
                if ($wasRateLimitedSomehow) {
                    // Get the job back on the queue for other worker server.
                    // All reset logic was treated inside the previous method.
                    return;
                }

                // Do we have a local ignoreRequestException method?
                $ignoreException = method_exists($this, 'ignoreRequestException') && $this->ignoreRequestException($e);

                // Do we have an exception handler ignoreRequestException?
                if (! $ignoreException && isset($this->exceptionHandler)) {
                    $ignoreException = $this->exceptionHandler->ignoreRequestException($e);
                }

                if (! $ignoreException) {
                    // Do we have a local resolveRequestException?
                    if (method_exists($this, 'resolveRequestException')) {
                        $this->resolveRequestException($e);
                    }

                    if (isset($this->exceptionHandler)) {
                        // If we do have on in exception handler, then it will also run.
                        $this->exceptionHandler->resolveRequestException($e);
                    }

                    // We are not ignoring the exception, so the job is marked as failed.
                    $this->updateToFailed($e);
                    throw $e;
                }

                // We decided to ignore the exception, all good. Job is closed.
                $this->coreJobQueue->finalizeDuration();
            }

            $this->coreJobQueue->updateToCompleted();
            $this->coreJobQueue->finalizeDuration();
        } catch (\Throwable $e) {
            // Not a RequestException at all.

            // Same logic applies but now for a resolveException fallback.
            if (method_exists($this, 'resolveException')) {
                $this->resolveException($e);
            }

            // Same for the exception handler resolveException.
            if (isset($this->exceptionHandler)) {
                $this->exceptionHandler->resolveException($e);
            }

            // Update CoreJobQueue.
            $this->updateToFailed($e);
            $this->coreJobQueue->finalizeDuration();

            // Finally, we throw the error for the parent class, if needed.
            throw $e;
        }
    }

    protected function computeAndStoreResult()
    {
        // Compute the result from the job
        $this->result = $this->compute();

        if ($this->result) {
            // Determine the serialized result
            $serializedResult = $this->serializeResult($this->result);

            // Save the serialized result to the database
            $this->coreJobQueue->update([
                'response' => $serializedResult,
            ]);
        }
    }

    protected function serializeResult($result)
    {
        if ($result instanceof ResponseInterface) {
            // Serialize Guzzle Response
            return json_encode([
                'status' => $result->getStatusCode(),
                'reason' => $result->getReasonPhrase(),
                'headers' => $result->getHeaders(),
                'body' => $this->getResponseBody($result),
            ]);
        }

        if (is_object($result)) {
            // Serialize object (e.g., model)
            return json_encode([
                'class' => get_class($result),
                'id' => method_exists($result, 'getKey') ? $result->getKey() : null,
            ]);
        }

        // Serialize other data types directly
        return json_encode($result);
    }

    protected function getResponseBody(\Psr\Http\Message\ResponseInterface $response)
    {
        $body = $response->getBody();

        // Ensure we rewind the body stream to read from the start
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Attempt to decode JSON body or fallback to raw string
        return json_decode($body, true) ?? (string) $body;
    }

    public function checkApiRequiredClasses()
    {
        if (!isset($this->rateLimiter)) {
            throw new \Exception('Rate Limiter class not instanciated on ' . static::class);
        }

        if (!isset($this->exceptionHandler)) {
            throw new \Exception('Rate Limiter class not instanciated on ' . static::class);
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

            // Polling limited? Re-dispatch.
        if ($isNowPollingLimited) {
            $this->updateToReseted();

            return true;
        }

        return false;
    }

    abstract protected function compute();
}
