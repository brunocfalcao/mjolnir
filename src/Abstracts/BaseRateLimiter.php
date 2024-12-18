<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\RateLimit;

abstract class BaseRateLimiter
{
    public array $forbidHttpCodes = [];

    public array $rateLimitHttpCodes = [429];

    public array $ignorableHttpCodes = [];

    // The account used for the rate limit / forbid logic.
    public Account $account;

    public function withAccount(Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Verifies if the Response or Request exception will now trigger
     * a rate limit action.
     */
    public function isNowRateLimited(RequestException|Response $input): bool
    {
        $statusCode = $this->extractStatusCode($input);

        return $statusCode && in_array($statusCode, $this->rateLimitHttpCodes, true);
    }

    /**
     * Verifies if the Response or Request exception will now trigger
     * a forbidden action.
     */
    public function isNowForbidden(RequestException|Response $input): bool
    {
        $statusCode = $this->extractStatusCode($input);

        return $statusCode && in_array($statusCode, $this->forbidHttpCodes, true);
    }

    // Applies a forbid rate limit action.
    public function forbid(): void
    {
        $this->applyPollingLimit();
    }

    // Or the child rate limit overrides this method, or defines the property.
    public function rateLimitbackoffSeconds()
    {
        if (property_exists($this, 'rateLimitbackoffSeconds')) {
            return $this->rateLimitbackoffSeconds;
        }

        throw new \Exception('No backoff rate limit duration property defined for '.get_class($this));
    }

    // Applies a rate limit action.
    public function rateLimit(): void
    {
        $this->applyPollingLimit(now()->addSeconds($this->rateLimitbackoffSeconds()));
    }

    // Applies the rate limit / forbid on the rate_limits table.
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

        return null;
    }

    // Returns the datetime carbon instance for the rate limit deadline.
    public function rateLimitedUntil(): Carbon
    {
        $rateLimit = RateLimit::where('account_id', $this->account->id)
            ->where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        // Is the rate limit a future timestamp?
        if ($rateLimit && $rateLimit->retry_after?->isFuture()) {
            return $rateLimit->retry_after;
        }
    }

    public function isRateLimited(): bool
    {
        $rateLimit = RateLimit::where('account_id', $this->account->id)
            ->where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        // Is the rate limit a future timestamp?
        if ($rateLimit && $rateLimit->retry_after?->isFuture()) {
            return true;
        }

        // Delete entry if it exists and is on the past.
        if ($rateLimit) {
            $rateLimit->delete();
        }

        return false;
    }

    // Checks if the current hostname is forbidden for this account.
    public function isForbidden(): bool
    {
        // Retrieve the RateLimit instance for the current API system and hostname
        $instance = RateLimit::where('api_system_id', $this->account->api_system_id)
            ->where('hostname', gethostname())
            ->first();

        // If no entry exists, it's not forbidden
        if (! $instance) {
            return false;
        }

        // Check if retry_after is null (forbidden state)
        return $instance->retry_after == null;
    }

    // Assesses if with the response, we will now need to forbid or rate limit.
    public function isNowLimited(RequestException|Response $response)
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
