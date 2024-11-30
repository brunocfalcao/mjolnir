<?php

namespace Nidavellir\Mjolnir\Abstracts;

use App\Models\Account;
use App\Models\RateLimit;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;

abstract class BaseRateLimiter
{
    public array $forbidHttpCodes;

    public array $rateLimitHttpCodes;

    public int $backoff;

    // The account used for the rate limit / forbid logic.
    public Account $account;

    public function withAccount(Account $account): self
    {
        $this->account = $account;

        return $this;
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

    public function throttle(): void
    {
        $this->applyPollingLimit(now()->addSeconds($this->backoff));
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
}
