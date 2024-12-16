<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\CoreJobQueue;
use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public CoreJobQueue $coreJobQueue;

    // Max retries for a "always pending" job. Then updates to "failed".
    public int $retries = 3;

    public function handle()
    {
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
                $this->rollback($e);
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
