<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Illuminate\Support\Facades\DB;
use Nidavellir\Thor\Models\JobQueue;

abstract class _GateKeeperJob extends BaseJobQueuable
{
    protected function perform()
    {
        $this->updateHostname();

        try {
            if (
                // global configuration to stop processing jobs.
                ! config('excalibur.allow_jobs_to_be_executed', true) ||

                // Previous job index was not completed.
                ! $this->checkPreviousJobCompletion() ||

                // Authorization was denied.
                (method_exists($this, 'authorize') && ! $this->authorize())) {
                $this->resetJob();

                return;
            }

            // Start the chrono.
            $this->startDuration();

            // Assign the job process sequencial index.
            $this->assignSequentialId();

            $this->compute();
            $this->complete();
            $this->finalizeDuration();
        } catch (\Throwable $e) {
            $this->finalizeDuration();
            $this->failed($e);
            throw $e;
        }
    }

    /**
     * Check if the previous job in the block (based on index) is complete.
     * If the current job has an index, it must wait for the previous indexed job to complete.
     */
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
        if ($this->jobQueue && $this->jobQueue->sequencial_id == null) {
            DB::transaction(function () {
                // Lock the JobQueue table to prevent race conditions
                $maxSequentialId = JobQueue::lockForUpdate()->max('sequencial_id');

                $sequentialId = ($maxSequentialId ?? 0) + 1;

                // Update the jobQueue entry with the new sequential ID
                $this->jobQueue->update(['sequencial_id' => $sequentialId]);
            });
        }
    }
}
