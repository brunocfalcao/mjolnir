<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;
use Nidavellir\Thor\Models\ApiRequestLog;

abstract class BaseApiClient
{
    protected string $baseURL;

    protected ?ApiCredentials $credentials; // Use ApiCredentials object for flexibility

    protected ?Client $httpRequest = null;

    public function __construct(string $baseURL, ?ApiCredentials $credentials = null)
    {
        $this->baseURL = $baseURL;
        $this->credentials = $credentials;
        $this->buildClient();
    }

    abstract protected function getHeaders(): array;

    protected function processRequest(ApiRequest $apiRequest, bool $sendAsJson = false)
    {
        $logData = [
            'path' => $apiRequest->path,
            'payload' => $apiRequest->properties->toArray(),
            'http_method' => $apiRequest->method,
            'http_headers_sent' => $this->getHeaders(),
            'hostname' => gethostname(),
        ];

        try {
            $options = [
                'headers' => $this->getHeaders(),
            ];

            if ($sendAsJson && strtoupper($apiRequest->method) !== 'GET') {
                $options['json'] = $apiRequest->properties->toArray();
            } else {
                $options['query'] = $apiRequest->properties->getOr('options', []);
            }

            $response = $this->httpRequest->request(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $logData['http_response_code'] = $response->getStatusCode();
            $logData['response'] = json_decode($response->getBody(), true);
            $logData['http_headers_returned'] = $response->getHeaders();

            $this->logApiRequest($logData);

            return $response;
        } catch (RequestException $e) {
            // Log the error details without re-throwing
            $logData['http_response_code'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            $logData['response'] = $e->getResponse() ? (string) $e->getResponse()->getBody() : null;
            $logData['http_headers_returned'] = $e->getResponse() ? $e->getResponse()->getHeaders() : null;

            $this->logApiRequest($logData);

            // Cascade exception so the e.g.: Core Job Queue can catch it.
            throw $e;
        } catch (\Throwable $e) {
            // Log the error log.
            $this->logApiRequest(['error_message' => $e->getMessage().' (line '.$e->getLine().')']);

            // Cascade exception so the e.g.: Core Job Queue can catch it.
            throw $e;
        }
    }

    protected function buildClient()
    {
        $this->httpRequest = new Client([
            'base_uri' => $this->baseURL,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/json',
                'User-Agent' => 'api-client-php',
            ],
            //'http_errors' => false,
        ]);
    }

    protected function logApiRequest(array $logData)
    {
        ApiRequestLog::create($logData);
    }

    protected function buildQuery(string $path, array $properties = []): string
    {
        return count($properties) == 0 ? $path : $path.'?'.http_build_query($properties);
    }
}
