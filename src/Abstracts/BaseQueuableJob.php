<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Nidavellir\Thor\Models\JobQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Nidavellir\Mjolnir\Abstracts\BaseJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

abstract class BaseQueableJob extends BaseJob
{
    protected $startedAt;

    protected $jobQueue;

    public function backoff(): array
    {
        return [5, 10, 15, 20];
    }

    public function handle()
    {
        try {
            $this->updateToRunning();
            $this->compute();
        } catch (\Throwable $e) {
            $this->updateToFailed($e);
            throw $e;
        }
    }

    protected function isLast()
    {
        return $this->jobQueue->index == JobQueue::where('block_uuid', $this->jobQueue->block_uuid)->max('index') ||
               $this->jobQueue->index == null;
    }

    protected function isFirst()
    {
        return $this->jobQueue->index == 1 ||
               $this->jobQueue->index == null;
    }

    protected function updateToRunning()
    {
        $this->updateJobQueue([
            'status' => 'running',
            'hostname' => gethostname()
        ]);

        $this->startDuration();
    }

    protected function updateToCompleted()
    {
        $this->updateJobQueue([
            'status' => 'completed'
        ]);

        $this->finalizeDuration();
    }

    private function startDuration()
    {
        $this->startedAt = microtime(true);
        $this->jobQueue->update(['started_at' => now()]);
    }

    protected function finalizeDuration()
    {
        $duration = intval((microtime(true) - $this->startedAt) * 1000);
        $this->jobQueue->update([
            'duration' => $duration,
        ]);
    }

    protected function updateJobQueue($updateData)
    {
        $this->jobQueue->update($updateData);
    }

    protected function updateToReseted()
    {
        $this->updateJobQueue([
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

    public function updateToFailed(\Throwable $e)
    {
        $this->updateJobQueue([
            'status' => 'failed',
            'error_message' => $e->getMessage().' (line '.$e->getLine().')',
            'error_stack_trace' => $e->getTraceAsString(),
        ]);

        $this->finalizeDuration();
    }

    protected function checkPreviousJobCompletion(): bool
    {
        if ($this->jobQueue->index == null) {
            // Non-indexed jobs can be executed immediately.
            return true;
        }

        $currentIndex = $this->jobQueue->index;
        $blockUuid = $this->jobQueue->block_uuid;

        // Check if there are any incomplete jobs or jobs with errors at the previous index.
        $previousIndex = $currentIndex - 1;
        $incompleteOrErroredJobs = JobQueue::where('block_uuid', $blockUuid)
            ->where('index', $previousIndex)
            ->whereNotIn('status', ['complete'])
            ->exists();

        // If there are incomplete or errored jobs with the previous index, return false.
        if ($incompleteOrErroredJobs) {
            return false;
        }

        return true;
    }

    protected function assignSequentialId()
    {
        if ($this->jobQueue->sequencial_id == null) {
            DB::transaction(function () {
                // Lock the JobQueue table to prevent race conditions
                $maxSequentialId = JobQueue::where('hostname', gethostname())->lockForUpdate()->max('sequencial_id');

                $sequentialId = ($maxSequentialId ?? 0) + 1;

                // Update the jobQueue entry with the new sequential ID
                $this->jobQueue->update(['sequencial_id' => $sequentialId]);
            });
        }
    }

    // Called at the GateKeeperJob child class (e.g., ApiCallJob).
    abstract protected function compute();
}
