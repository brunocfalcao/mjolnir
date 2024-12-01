<?php

namespace Nidavellir\Mjolnir\Middleware;

use Nidavellir\Thor\Models\JobQueue;

class EnsureJobQueueSequence
{
    protected $jobQueue;

    public function __construct($jobQueue)
    {
        $this->jobQueue = $jobQueue;
    }

    public function handle($job, Closure $next)
    {
        // If the job is not indexed, allow it to proceed
        if ($this->jobQueue->index == null) {
            return $next($job);
        }

        $currentIndex = $this->jobQueue->index;
        $blockUuid = $this->jobQueue->block_uuid;

        // Check if there are any incomplete or errored jobs at the previous index
        $previousIndex = $currentIndex - 1;
        $incompleteOrErroredJobs = JobQueue::where('block_uuid', $blockUuid)
            ->where('index', $previousIndex)
            ->whereNotIn('status', ['completed'])
            ->exists();

        if ($incompleteOrErroredJobs) {
            // Reset the current job to "pending" and exit
            $this->jobQueue->updateToReseted();

            return;
        }

        // Proceed to the next middleware or job execution
        return $next($job);
    }
}
