<?php

namespace Nidavellir\Mjolnir\Exceptions\Stubs;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class KrakenExceptionStub extends BaseExceptionStub
{
    public function __construct(array $exceptionDetails = [])
    {
        // Define Kraken-specific defaults
        $defaults = [
            'http_code' => 429,
            'message' => 'Kraken rate limit exceeded.',
            'headers' => [
                'Retry-After' => '30', // Kraken typically uses "Retry-After" for rate-limiting
            ],
            'body' => 'Rate limit exceeded for Kraken API.',
            'endpoint' => '/api/v1/order',
        ];

        // Merge defaults with provided details
        $this->exceptionDetails = array_merge($defaults, $exceptionDetails);
    }

    /**
     * Simulate a Rate Limit Exceeded (HTTP 429) exception.
     */
    public function simulateRateLimitExceeded(): \Exception
    {
        $response = new Response(
            429,
            [
                'Retry-After' => $this->exceptionDetails['headers']['Retry-After'] ?? '30',
            ],
            'Rate limit exceeded.'
        );

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/api/v1/order'),
            $response
        );
    }

    /**
     * Simulate a Forbidden (HTTP 403) exception.
     */
    public function simulateForbidden(): \Exception
    {
        $response = new Response(
            403,
            [],
            'Forbidden: Access denied to this resource.'
        );

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/api/v1/private/account'),
            $response
        );
    }
}
