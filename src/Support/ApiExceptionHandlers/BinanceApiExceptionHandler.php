<?php

namespace Nidavellir\Mjolnir\Support\ApiExceptionHandlers;

use App\Abstracts\BaseApiExceptionHandler;
use GuzzleHttp\Exception\RequestException;

class BinanceApiExceptionHandler extends BaseApiExceptionHandler
{
    public function ignoreRequestException(RequestException $exception): bool
    {
        // Define a mapping of status codes and optional error codes.
        $statusCodesMap = [
            400 => -4046, // No need to change the margin type (ignore error).
        ];

        $statusCode = $exception->getResponse()->getStatusCode();
        $responseBody = json_decode($exception->getResponse()->getBody(), true);

        foreach ($statusCodesMap as $httpStatusCode => $code) {
            if ($statusCode == $httpStatusCode) {
                if ($code == null) {
                    return true;
                }

                if (array_key_exists('code', $responseBody)) {
                    if ($responseBody['code'] == $code) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function resolveRequestException(\Throwable $exception)
    {
        // Check if the exception is a Guzzle RequestException and has a response
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $statusCode = $exception->getResponse()->getStatusCode();
            $responseBody = json_decode($exception->getResponse()->getBody()->getContents(), true);

            if (is_array($responseBody) && isset($responseBody['code'], $responseBody['msg']) &&
            $responseBody['code'] == '-2015' && $statusCode == 401) {
                notify(
                    title: 'BINANCE Api exception',
                    message: gethostname().' - '.$responseBody['msg'],
                    application: 'nidavellir_errors'
                );

                return; // Exit early since this is a specific case
            }
        }

        // Generic fallback for other exceptions
        notify(
            title: 'Unhandled Exception',
            message: gethostname().' - '.$exception->getMessage(),
            application: 'nidavellir_errors'
        );
    }
}
