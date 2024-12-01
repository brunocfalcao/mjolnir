<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Thor\Models\JobQueue;

abstract class BaseJobQueuable implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    protected $startedAt;

    protected $jobQueue;

    public function backoff(): array
    {
        return [5, 10, 15, 20];
    }

    public function handle()
    {
        try {
            $this->updateHostname();
            $this->updateJobQueue(['status' => 'running']);
            $this->perform();
        } catch (\Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }

    public function withJobQueue(JobQueue $jobQueue)
    {
        $this->jobQueue = $jobQueue;

        return $this;
    }

    protected function updateHostname()
    {
        $hostname = gethostname();
        $this->jobQueue->update(['hostname' => $hostname]);
    }

    protected function complete()
    {
        $this->jobQueue->update([
            'status' => 'complete',
            'completed_at' => now(),
        ]);
    }

    protected function startDuration()
    {
        $this->startedAt = microtime(true);
        $this->jobQueue->update(['started_at' => now()]);
    }

    protected function updateJobQueue($updateData)
    {
        $this->jobQueue->update($updateData);
    }

    protected function finalizeDuration()
    {
        $duration = intval((microtime(true) - $this->startedAt) * 1000);
        $this->jobQueue->update([
            'duration' => $duration,
        ]);
    }

    protected function resetJob()
    {
        $this->jobQueue->update([
            'status' => 'pending',
            'error_message' => null,
            'error_stack_trace' => null,
            'duration' => null,
            'started_at' => null,
            'completed_at' => null,
            'sequencial_id' => null,
            'hostname' => null,
        ]);
    }

    public function failed(\Throwable $e)
    {
        $this->updateJobQueue([
            'hostname' => gethostname(),
            'status' => 'failed',
            'error_message' => $e->getMessage().' (line '.$e->getLine().')',
            'error_stack_trace' => $e->getTraceAsString(),
        ]);

        $this->finalizeDuration();
    }

    // Called at the abstract JobGateKeeper level.
    abstract protected function perform();

    // Called at the GateKeeperJob child class (e.g., ApiCallJob).
    abstract protected function compute();
}
