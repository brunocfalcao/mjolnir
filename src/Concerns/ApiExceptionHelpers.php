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
            $errorData = extract_http_code_and_status_code($exception);
            $httpCode = $errorData['http_code'];
            $statusCode = $errorData['status_code'];

            if (isset($statusCodes[$httpCode])) {
                $codes = $statusCodes[$httpCode];

                // If specific codes are provided, check if the response code matches
                if (is_array($codes) && ! is_null($statusCode)) {
                    return in_array($statusCode, $codes, true);
                }

                // Handle all responses for this status code if no specific codes are provided
                return is_null($codes);
            }

            // If the status code is directly present as an integer in the array, handle it
            return in_array($httpCode, $statusCodes, true);
        } catch (\Throwable $e) {
            // Fallback, we should not handle the exception.
            return false;
        }
    }

    private function getResponseBody(RequestException $exception): array
    {
        $body = $exception->getResponse()->getBody();

        return json_decode($body, true) ?? [];
    }
}
