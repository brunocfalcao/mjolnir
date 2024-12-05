<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\CoreJobQueue;
use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    public function handle()
    {
        /**
         * The refresh method will be triggered if a job is being retried.
         * This allows us, for instance, to re-run a specific computation
         * on the child job to refresh timestamps, variables, information.
         *
         * This method is only called after a first execution of the job.
         */
        if (method_exists($this, 'refresh') && $this->coreJobQueue->shouldBeRefreshed()) {
            $this->refresh();
        }

        try {
            $this->coreJobQueue->updateToRunning();
            $this->coreJobQueue->startDuration();

            $this->computeAndStoreResult();

            // Did the child job touched the status or the duration? -- Yes, skip.
            if (! $this->coreJobQueue->wasChanged('status')) {
                $this->coreJobQueue->updateToCompleted();
            }
            if (! $this->coreJobQueue->wasChanged('duration')) {
                $this->coreJobQueue->finalizeDuration();
            }
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

            // Do we have a rollback option?
            if (method_exists($this, 'rollback')) {
                $this->rollback();
                $this->coreJobQueue->updateToRollbacked();
            } else {
                // No rollback? Then update to failed and it's done.
                $this->coreJobQueue->updateToFailed($e);
            }

            $this->coreJobQueue->finalizeDuration();

            /**
             * No need to cascade the exception since it was fully managed by
             * any child BaseQueuableJob class, or by this class.
             */
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

    abstract protected function compute();
}
