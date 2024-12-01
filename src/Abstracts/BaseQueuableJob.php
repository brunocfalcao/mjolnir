<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Nidavellir\Thor\Models\JobQueue;

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
