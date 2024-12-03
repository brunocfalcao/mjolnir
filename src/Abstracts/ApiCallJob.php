<?php

namespace App\Jobs\GateKeepers;

use App\Abstracts\BaseApiExceptionHandler;
use App\Abstracts\BaseRateLimiter;
use App\Abstracts\GateKeeperJob;
use App\Contracts\GateKeeperJobContract;
use App\Models\RateLimit;
use GuzzleHttp\Exception\RequestException;

abstract class ApiCallJob extends GateKeeperJob implements GateKeeperJobContract
{
    public BaseRateLimiter $rateLimiter;

    public BaseApiExceptionHandler $exceptionHandler;

    public function authorize(): bool
    {
        if (! isset($this->rateLimiter)) {
            $this->failed();
            throw new \Exception('No rate limiter class defined for the class '.static::class);
        }

        // Worker server rate limited for this account?
        return ! $this->isPollingLimited();
    }

    // Verify if this worker server is poll limited for this account/ip.
    public function isPollingLimited(): bool
    {
        $rateLimit = RateLimit::where('account_id', $this->rateLimiter->account->id)
            ->where('api_system_id', $this->rateLimiter->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        if ($rateLimit && $rateLimit->retry_after?->isFuture()) {
            return true;
        }

        $forbidden = RateLimit::where('api_system_id', $this->rateLimiter->account->api_system_id)
            ->where('hostname', gethostname())
            ->where('retry_after', null)
            ->first();

        return $forbidden != null;
    }

    public function call(callable $caller, ...$args)
    {
        try {
            // Make the API call.
            $response = $caller(...$args);

            if ($response) {
                $this->rateLimiter->isNowRateLimited($response)
                     && $this->rateLimiter->throttle();

                $this->rateLimiter->isNowForbidden($response)
                    && $this->rateLimiter->forbid();

                return $response;
            }
        } catch (RequestException $e) {
            $isNowPollingLimited = false;

            if ($this->rateLimiter->isNowRateLimited($e)) {
                $this->rateLimiter->throttle();
                $isNowPollingLimited = true;
            }

            if ($this->rateLimiter->isNowForbidden($e)) {
                $this->rateLimiter->forbid();
                $isNowPollingLimited = true;
            }

            // Polling limited? Re-dispatch.
            if ($isNowPollingLimited) {
                $this->resetJob();

                return;
            }

            $result = method_exists($this, 'ignoreRequestException') && $this->ignoreRequestException($e);

            if (! $result && isset($this->exceptionHandler)) {
                $result = $this->exceptionHandler->ignoreRequestException($e);
            }

            if (! $result) {
                if (method_exists($this, 'resolveRequestException')) {
                    $this->resolveRequestException($e);
                }

                if (isset($this->exceptionHandler)) {
                    $this->exceptionHandler->resolveRequestException($e);
                }

                $this->failed($e);
                throw $e;
            }

            // The error was marked as to ignore, so we just finalize the job.
            $this->finalizeDuration();
        } catch (\Throwable $e) {
            if (method_exists($this, 'resolveRequestException')) {
                $this->resolveRequestException($e);
            }

            // Now the base request exception handler.
            if (isset($this->exceptionHandler)) {
                $this->exceptionHandler->resolveRequestException($e);
            }

            $this->failed($e);
            throw $e;
        }
    }

    /**
     * The main execution logic of the child job.
     * Must be implemented by child classes.
     */
    abstract public function compute();
}
