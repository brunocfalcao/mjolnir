<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Exceptions\NonOverridableException;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\User;
use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    public int $workerServerBackoffSeconds = 5;

    public bool $coreJobQueueStatusUpdated = false;

    public ?BaseExceptionHandler $exceptionHandler;

    public function handle()
    {
        try {
            $this->coreJobQueue->startDuration();

            // Max retries reached?
            if ($this->coreJobQueue->retries == $this->retries + 1) {
                throw new NonOverridableException('CoreJobQueue max retries reached');
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
            if ($e instanceof NonOverridableException) {
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

            /**
             * We will try to run the 3 exception handler methods from the
             * exceptionHandler if exists. Then we will run the local ones.
             */

            // Should gracefully retry the core job?
            if (method_exists($this, 'retryException')) {
                $shouldRetry = $this->retryException($e);
            }

            if (isset($this->exceptionHandler) && method_exists($this->exceptionHandler, 'retryException')) {
                $orShouldRetry = $this->exceptionHandler->retryException($e);
            }

            if ((isset($shouldRetry) && $shouldRetry) || (isset($orShouldRetry) && $orShouldRetry)) {
                // If we have a method call beforeRetry(\Throwable $e), run it.
                if (method_exists($this, 'beforeRetry')) {
                    $this->beforeRetry($e);
                }

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

            if (! $this->coreJobQueueStatusUpdated) {
                // Update to failed, and it's done.
                $this->coreJobQueue->updateToFailed($e);
                $this->coreJobQueue->finalizeDuration();

                User::admin()->get()->each(function ($user) use ($e) {
                    $user->pushover(
                        message: "[{$this->coreJobQueue->id}] - ".$e->getMessage(),
                        title: 'Core Job Queue Error',
                        applicationKey: 'nidavellir_errors'
                    );
                });
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

    abstract protected function compute();
}
