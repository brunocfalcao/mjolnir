<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Thor\Models\ApiJob;

abstract class ApiableJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $apiJob;

    protected $workerHostname;

    public function __construct($apiJobId)
    {
        $this->apiJobId = $apiJobId;
    }

    public function handle()
    {
        $this->apiJob = ApiJob::find($this->apiJobId);

        if (! $this->apiJob || $this->apiJob->status !== 'pending') {
            return;
        }

        if (method_exists($this, 'regenerateParameters')) {
            $this->regenerateParameters();
        }

        if (! $this->checkAccountRateLimits()) {
            return;
        }

        $this->workerHostname = $this->getAuthorizedWorkerHostname();

        if (! $this->workerHostname) {
            $this->release(10);

            return;
        }

        try {
            $this->compute();

            $this->apiJob->update([
                'status' => 'completed',
                'worker_hostname' => $this->workerHostname,
            ]);
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    abstract public function compute();

    private function checkAccountRateLimits()
    {
        $rateLimit = ApiAccountRateLimit::where('account_id', $this->apiJob->account_id)->first();

        if (! $rateLimit) {
            return true;
        }

        if ($rateLimit->is_rate_limited) {
            if ($rateLimit->until && $rateLimit->until->isPast()) {
                $rateLimit->delete();

                return true;
            }
            $this->release(30);

            return false;
        }

        if ($rateLimit->is_forbidden) {
            $this->release(10);

            return false;
        }

        return true;
    }

    private function getAuthorizedWorkerHostname()
    {
        return ApiAccountRateLimit::where('account_id', $this->apiJob->account_id)
            ->where('is_rate_limited', false)
            ->where('is_forbidden', false)
            ->value('hostname');
    }

    private function markRateLimited($hostname, $accountId, $backoffInSeconds)
    {
        ApiAccountRateLimit::updateOrCreate(
            ['hostname' => $hostname, 'account_id' => $accountId],
            [
                'is_rate_limited' => true,
                'until' => now()->addSeconds($backoffInSeconds),
            ]
        );
    }

    private function markWorkerForbidden($hostname, $accountId)
    {
        ApiAccountRateLimit::updateOrCreate(
            ['hostname' => $hostname, 'account_id' => $accountId],
            ['is_forbidden' => true]
        );
    }

    private function handleException($exception)
    {
        $responseCode = $this->extractHttpStatusCode($exception);

        switch ($responseCode) {
            case 429:
                $backoffInSeconds = $this->getRateLimitBackoff($exception);
                $this->markRateLimited($this->workerHostname, $this->apiJob->account_id, $backoffInSeconds);
                $this->release($backoffInSeconds);
                break;

            case 418:
            case 403:
                $this->markWorkerForbidden($this->workerHostname, $this->apiJob->account_id);
                $this->release(10);
                break;

            default:
                $this->apiJob->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
                break;
        }
    }

    private function extractHttpStatusCode($exception)
    {
        return method_exists($exception, 'getCode') ? $exception->getCode() : 0;
    }

    private function getRateLimitBackoff($exception)
    {
        $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

        return $retryAfter ? intval($retryAfter) : 30;
    }
}
