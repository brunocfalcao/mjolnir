<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Exceptions\JustEndException;
use Nidavellir\Mjolnir\Exceptions\JustResolveException;
use Nidavellir\Mjolnir\Exceptions\MaxRetriesReachedException;
use Nidavellir\Thor\Models\CoreJobQueue;
use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    public int $workerServerBackoffSeconds = 10;

    public bool $coreJobQueueStatusUpdated = false;

    public ?BaseExceptionHandler $exceptionHandler;

    public function handle()
    {
        try {
            // Store the loggable information in the session.
            session()->put('api_requests_log_loggable_id', $this->coreJobQueue->id);
            session()->put('api_requests_log_loggable_class', get_class($this->coreJobQueue));

            $this->coreJobQueue->startDuration();

            // Max retries reached?
            if ($this->coreJobQueue->retries == $this->retries + 1) {
                $this->coreJobQueue->update(['max_retries_reached' => true]);
                throw new MaxRetriesReachedException;
            }

            // Quick authorization method on the child job.
            if (method_exists($this, 'authorize')) {
                $result = $this->authorize();

                if (! is_bool($result)) {
                    throw new \Exception('The authorize method did not return a boolean');
                }

                if ($result == false) {
                    return $this->coreJobQueue->updateToRetry(now()->addSeconds($this->workerServerBackoffSeconds));
                }
            }

            $this->coreJobQueue->updateToRunning();

            // Punch it.
            $this->computeAndStoreResult();

            /**
             * Sometimes a child job will update core job queue and don't want
             * the base queueable job to re-update it again. E.g.: BaseApiableJob
             * when thrown a RequestException.
             */
            if (! $this->coreJobQueueStatusUpdated) {
                // Complete core job queue instance.
                $this->coreJobQueue->finalizeDuration();
                $this->coreJobQueue->updateToCompleted();
            }

            // All good.
        } catch (\Throwable $e) {
            if ($e instanceof ConnectException) {
                // For connection exceptions, we just need to retry again the job.
                $this->coreJobQueue->updateToRetry(now()->addSeconds($this->workerServerBackoffSeconds));

                return;
            }

            // If it's a cURL network error, we can also retry the job again.
            if ($e instanceof RequestException && strpos($e->getMessage(), 'cURL error') !== false) {
                // For connection exceptions, we just need to retry again the job.
                $this->coreJobQueue->updateToRetry(now()->addSeconds($this->workerServerBackoffSeconds));

                return;
            }

            if ($e instanceof MaxRetriesReachedException) {
                // Last try to make things like a rollback.
                if (method_exists($this, 'resolveException')) {
                    $this->resolveException($e);
                }

                if (method_exists($this->exceptionHandler, 'resolveException')) {
                    $this->exceptionHandler->resolveException($e);
                }

                if (! $this->coreJobQueueStatusUpdated) {
                    // Update to failed, and it's done.
                    $this->coreJobQueue->updateToFailed("Core Job Queue [{$this->coreJobQueue->id}] - Max retries reached ({$this->coreJobQueue->class})");
                    $this->coreJobQueue->finalizeDuration();
                }

                return;
            }

            if ($e instanceof JustResolveException) {
                // Last try to make things like a rollback.
                if (method_exists($this, 'resolveException')) {
                    $this->resolveException($e);
                }

                if (method_exists($this->exceptionHandler, 'resolveException')) {
                    $this->exceptionHandler->resolveException($e);
                }

                if (! $this->coreJobQueueStatusUpdated) {
                    // Update to failed, and it's done.
                    $this->coreJobQueue->updateToFailed($e);
                    $this->coreJobQueue->finalizeDuration();
                }

                return;
            }

            if ($e instanceof JustEndException) {
                if (! $this->coreJobQueueStatusUpdated) {
                    // Update to failed, and it's done.
                    $this->coreJobQueue->updateToFailed($e);
                    $this->coreJobQueue->finalizeDuration();
                }

                // propagate exception.
                // throw $e;

                return;
            }

            /**
             * We will try to run the 4 exception handler methods from the
             * exceptionHandler if exists. Then we will run the local ones.
             *
             * The global methods always run prior to the local methods.
             */

            // Should gracefully retry (and if so before-retry) the core job?
            if (isset($this->exceptionHandler) && method_exists($this->exceptionHandler, 'retryException')) {
                $orShouldRetry = $this->exceptionHandler->retryException($e);
            }

            if (method_exists($this, 'retryException')) {
                $shouldRetry = $this->retryException($e);
            }

            if ((isset($shouldRetry) && $shouldRetry) || (isset($orShouldRetry) && $orShouldRetry)) {
                $this->coreJobQueue->updateToRetry(now()->addSeconds($this->workerServerBackoffSeconds));

                return;
            }

            // Should gracefully ignore the exception?
            if (method_exists($this, 'ignoreException')) {
                $shouldIgnore = $this->ignoreException($e);
            }

            if (isset($this->exceptionHandler) && method_exists($this->exceptionHandler, 'ignoreException')) {
                $orShouldIgnore = $this->exceptionHandler->ignoreException($e);
            }

            if ((isset($shouldIgnore) && $shouldIgnore) || (isset($orShouldIgnore) && $orShouldIgnore)) {
                if (! $this->coreJobQueueStatusUpdated) {
                    $this->coreJobQueue->updateToCompleted();
                    $this->coreJobQueue->finalizeDuration();
                }

                return;
            }

            // Last try to make things like a rollback.
            if (method_exists($this, 'resolveException')) {
                $this->resolveException($e);
            }

            if (method_exists($this->exceptionHandler, 'resolveException')) {
                $this->exceptionHandler->resolveException($e);
            }

            // Did something already updated the core job queue status? -- yes = skip then.
            if (! $this->coreJobQueueStatusUpdated) {
                // Update to failed, and it's done.
                $this->coreJobQueue->updateToFailed($e);
                $this->coreJobQueue->finalizeDuration();
                // propagate exception.
                throw $e;
            }
        }
    }

    protected function computeAndStoreResult()
    {
        // Compute the result from the job
        $result = $this->compute();

        if ($result) {
            // Determine the serialized result
            $serializedResult = $this->serializeResult($result);

            /**
             * Update result, but only if it wasn't updated before.
             */
            if ($this->coreJobQueue->response == null) {
                $this->coreJobQueue->update([
                    'response' => $serializedResult,
                ]);
            }
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

    public function failed(\Throwable $e): void
    {
        $this->coreJobQueue->updateToFailed($e);
    }

    abstract protected function compute();
}
