<?php

namespace Nidavellir\Mjolnir\Concerns;

use GuzzleHttp\Exception\RequestException;

trait ApiExceptionHelpers
{
    public function retryException(\Throwable $exception): bool
    {
        return $this->shouldHandleException($exception, $this->httpRetryableStatusCodes);
    }

    public function ignoreException(\Throwable $exception): bool
    {
        return $this->shouldHandleException($exception, $this->httpIgnorableStatusCodes);
    }

    private function shouldHandleException(\Throwable $exception, array $statusCodes): bool
    {
        // Check if the exception is a Guzzle RequestException
        if (! $exception instanceof RequestException) {
            return false;
        }

        try {
            // If we have a response, we can continue.
            if ($exception->getResponse() != null) {
                $statusCode = $exception->getResponse()->getStatusCode();

                $responseBody = $this->getResponseBody($exception);

                if (isset($statusCodes[$statusCode])) {
                    $codes = $statusCodes[$statusCode];

                    // If specific codes are provided, check if the response code matches
                    if (is_array($codes) && isset($responseBody['code'])) {
                        return in_array($responseBody['code'], $codes);
                    }

                    // Handle all responses for this status code if no specific codes are provided
                    return is_null($codes);
                }

                // If the status code is directly present as an integer in the array, handle it
                if (in_array($statusCode, $statusCodes, true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Fallback, we should not handle the exception.
            return false;
        }

        return false;
    }

    private function getResponseBody(RequestException $exception): array
    {
        $body = $exception->getResponse()->getBody();

        return json_decode($body, true) ?? [];
    }
}
