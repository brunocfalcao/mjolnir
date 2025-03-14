<?php

namespace Nidavellir\Mjolnir\Concerns;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;

trait DefaultRetriableException
{
    public function retryException(\Throwable $e)
    {
        if ($e instanceof RequestException) {
            $codes = extract_http_code_and_status_code($e);

            if ($codes['http_code'] == 400 && $codes['status_code'] == '-1021') {
                Artisan::call('mjolnir:update-recvwindow-safety-duration');
            }
        }
    }
}
