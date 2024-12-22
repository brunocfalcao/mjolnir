<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\CoreJobQueue;
use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    public int $workerServerBackoffSeconds = 5;

    // Max retries for a "always pending" job. Then update to "failed".
    public int $retries = 3;

    public function handle()
    {
        try {
            $this->coreJobQueue->startDuration();

            // Max retries reached?
            if ($this->coreJobQueue->retries == $this->retries + 1) {
                throw new \Exception('CoreJobQueue max retries reached');
            }

            // Quick authorization method on the child job.
            if (method_exists($this, 'authorize')) {
                $result = $this->authorize();

                if (! is_bool($result)) {
                    throw new \Exception('The authorize method did not return a boolean');
                }

                if ($result == false) {
                    $this->coreJobQueue->updateToRetry();

                    return;
                }
            }

            $this->coreJobQueue->updateToRunning();

            // Did the child job touched the status or the duration? -- Yes, skip.
            if (! $this->coreJobQueue->wasChanged('status')) {
                $this->coreJobQueue->updateToCompleted();
            }
            if (! $this->coreJobQueue->wasChanged('duration')) {
                $this->coreJobQueue->finalizeDuration();
            }
        } catch (\Throwable $e) {
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
                $this->coreJobQueue->updateToRetry();

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
                $this->coreJobQueue->updateToCompleted();
                $this->coreJobQueue->finalizeDuration();

                return;
            }

            /**
             * The resolve exception can be used for rollback calls, etc.
             * But it will always escalate the exception.
             */
            if (method_exists($this, 'resolveException')) {
                $this->resolveException($e);
            }

            if (method_exists($this->exceptionHandler, 'resolveException')) {
                $this->exceptionHandler->resolveException($e);
            }

            // Update to failed, and it's done.
            $this->coreJobQueue->updateToFailed($e);
            $this->coreJobQueue->finalizeDuration();
        }
    }

    protected function computeAndStoreResult()
    {
        // Compute the result from the job
        $this->result = $this->compute();

        if ($this->result) {
            // Determine the serialized result
            $serializedResult = $this->serializeResult($this->result);

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
