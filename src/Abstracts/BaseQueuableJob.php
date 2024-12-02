<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Psr\Http\Message\ResponseInterface;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public $coreJobQueue;

    public function handle()
    {
        try {
            $this->coreJobQueue->updateToRunning();
            $this->coreJobQueue->startDuration();
            $this->computeAndStoreResult();
            $this->coreJobQueue->updateToCompleted();
            $this->coreJobQueue->finalizeDuration();
        } catch (\Throwable $e) {
            $this->coreJobQueue->updateToFailed($e);
            $this->coreJobQueue->finalizeDuration();
            throw $e;
        }
    }

    protected function computeAndStoreResult()
    {
        // Compute the result from the job
        $result = $this->compute();

        // Determine the serialized result
        $serializedResult = $this->serializeResult($result);

        // Save the serialized result to the database
        $this->coreJobQueue->update([
            'response' => $serializedResult,
        ]);
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
