<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\RateLimit;

abstract class BaseRateLimiter
{
    public ?array $forbidHttpCodes = [];

    public ?array $rateLimitHttpCodes = [];

    public ?array $ignorableHttpCodes = [];

    public ?array $retryableHttpCodes = [];

    public ?int $rateLimitbackoff = null;

    // This is the default worker server backoff seconds recorded on the rate_limits.
    public int $rateLimitbackoffSeconds = 5;

    /**
     * This is the backoff seconds on the CoreJobQueue entry to allow
     * a order worker server to pick the job.
     */
    public int $workerServerBackoffSeconds = 5;

    // The account used for the rate limit / forbid logic.
    public Account $account;

    public function withAccount(Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function workerServerBackoffSeconds()
    {
        return now()->addSeconds($this->workerServerBackoffSeconds);
    }

    public function isNowRateLimited(RequestException|Response $input): bool
    {
        $statusCode = $this->extractStatusCode($input);

        return $statusCode && in_array($statusCode, $this->rateLimitHttpCodes, true);
    }

    public function isNowForbidden(RequestException|Response $input): bool
    {
        $statusCode = $this->extractStatusCode($input);

        return $statusCode && in_array($statusCode, $this->forbidHttpCodes, true);
    }

    public function forbid(): void
    {
        $this->applyPollingLimit();
    }

    // Returns the datetime that the rate limit is lifted.
    public function throttle(): Carbon
    {
        $this->applyPollingLimit(now()->addSeconds($this->rateLimitbackoffSeconds));

        return now()->addSeconds($this->rateLimitbackoffSeconds);
    }

    protected function applyPollingLimit(?Carbon $retryAfter = null): void
    {
        $attributes = [
            'account_id' => $this->account->id,
            'api_system_id' => $this->account->api_system_id,
            'hostname' => gethostname(),
        ];

        // Format the Carbon instance to avoid issues with microseconds.
        $values = [
            'retry_after' => $retryAfter ? $retryAfter->format('Y-m-d H:i:s') : null,
        ];

        RateLimit::updateOrCreate($attributes, $values);
    }

    /**
     * Extracts the HTTP status code from a Response or RequestException.
     */
    protected function extractStatusCode(RequestException|Response $input): ?int
    {
        if ($input instanceof Response) {
            return $input->getStatusCode();
        }

        if ($input instanceof RequestException && $input->hasResponse()) {
            return $input->getResponse()->getStatusCode();
        }

        return null; // No status code available.
    }

    public function isRateLimited()
    {
        $rateLimit = RateLimit::where('account_id', $this->account->id)
            ->where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        // Is the rate limit a future timestamp?
        if ($rateLimit && $rateLimit->retry_after?->isFuture()) {
            return $rateLimit->retry_after;
        }

        // Delete entry if it exists and is on the past.
        if ($rateLimit) {
            $rateLimit->delete();
        }

        return null;
    }

    public function isForbidden()
    {
        // Do we have an entry, at least?
        $instance = RateLimit::where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname());

        if (! $instance) {
            return false;
        }

        // Is the worker server forbidden on this account?
        return RateLimit::where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname())
            ->where('retry_after', null)
            ->first();
    }

    // Assesses if with the response, we will now need to forbid or rate limit.
    public function assessPollingLimit(Response $response)
    {
        $wasPolledLimited = false;
        if ($this->isNowRateLimited($response)) {
            $wasPolledLimited = true;
            $this->throttle();
        }

        if ($this->isNowForbidden($response)) {
            $wasPolledLimited = true;
            $this->forbid();
        }

        return $wasPolledLimited;
    }
}
