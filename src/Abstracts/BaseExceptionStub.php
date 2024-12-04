<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class BaseExceptionStub
{
    protected array $exceptionDetails;

    public function __construct(array $exceptionDetails = [])
    {
        $this->exceptionDetails = $exceptionDetails;
    }

    public function simulateRequestException(): \Exception
    {
        $httpCode = $this->exceptionDetails['http_code'] ?? 500;
        $message = $this->exceptionDetails['message'] ?? 'Default exception message';
        $headers = $this->exceptionDetails['headers'] ?? [];
        $body = $this->exceptionDetails['body'] ?? '';

        $response = new Response($httpCode, $headers, $body);

        return RequestException::create(
            new Request('GET', $this->exceptionDetails['endpoint'] ?? '/default-endpoint'),
            $response
        );
    }
}
