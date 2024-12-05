<?php

namespace Nidavellir\Mjolnir\Exceptions\Stubs;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionStub;

class BinanceExceptionStub extends BaseExceptionStub
{
    public function __construct(array $exceptionDetails = [])
    {
        // Define Binance-specific defaults
        $defaults = [
            'http_code' => 400,
            'message' => 'Bad Request.',
            'headers' => [],
            'body' => '{"code":-1000,"msg":"Unknown error occurred while processing the request."}',
            'endpoint' => '/fapi/v1/exchangeInfo',
        ];

        // Merge defaults with provided details
        $this->exceptionDetails = array_merge($defaults, $exceptionDetails);
    }

    /**
     * Simulate a Rate Limit Exceeded (HTTP 429) exception.
     */
    public function simulateRateLimitExceeded(): \Exception
    {
        // Create a mocked response for the Rate Limit Exceeded case
        $response = new Response(429, [
            'X-MBX-USED-WEIGHT-1m' => $this->exceptionDetails['headers']['X-MBX-USED-WEIGHT-1m'] ?? '1200',
            'Retry-After' => '60', // Suggest a retry time of 60 seconds
        ], 'Rate limit exceeded.');

        // Create and return a Guzzle RequestException without making an actual HTTP request
        return new RequestException(
            'Rate limit exceeded.', // Message
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/exchangeInfo'), // Mocked request
            $response // Mocked response
        );
    }

    /**
     * Simulate an IP Ban (HTTP 418).
     */
    public function simulateIpBan(): \Exception
    {
        $response = new Response(418, [
            'X-MBX-USED-WEIGHT-1m' => $this->exceptionDetails['headers']['X-MBX-USED-WEIGHT-1m'] ?? '1500',
        ], 'IP banned due to repeated rate limit violations.');

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/exchangeInfo'),
            $response
        );
    }

    /**
     * Simulate a Forbidden (HTTP 403) exception.
     */
    public function simulateForbidden(): \Exception
    {
        $response = new Response(403, [], 'Forbidden: Access denied to this resource.');

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/private/account'),
            $response
        );
    }

    /**
     * Simulate an Unknown Error (HTTP 400, Code -1000).
     */
    public function simulateUnknownError(): \Exception
    {
        $response = new Response(400, [], json_encode([
            'code' => -1000,
            'msg' => '[Stub] Unknown error occurred while processing the request.',
        ]));

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/exchangeInfo'),
            $response
        );
    }

    /**
     * Simulate a Disconnected Error (HTTP 400, Code -1001).
     */
    public function simulateDisconnectedError(): \Exception
    {
        $response = new Response(400, [], json_encode([
            'code' => -1001,
            'msg' => '[Stub] Internal error; unable to process your request. Please try again.',
        ]));

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/exchangeInfo'),
            $response
        );
    }

    /**
     * Simulate an Unauthorized Error (HTTP 400, Code -1002).
     */
    public function simulateUnauthorizedError(): \Exception
    {
        $response = new Response(400, [], json_encode([
            'code' => -1002,
            'msg' => '[Stub] You are not authorized to execute this request.',
        ]));

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/fapi/v1/exchangeInfo'),
            $response
        );
    }
}
