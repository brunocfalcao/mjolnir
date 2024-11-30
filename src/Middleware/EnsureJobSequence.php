<?php

namespace Nidavellir\Mjolnir\Middleware;

use Closure;
use Nidavellir\Thor\Models\ApiJob;

class EnsureJobSequence
{
    public function handle($job, Closure $next)
    {
        if (! property_exists($job, 'apiJob') || ! $job->apiJob instanceof ApiJob) {
            return $next($job); // Skip if the job does not use the ApiJob model
        }

        $apiJob = $job->apiJob;

        // Retrieve all previous jobs in the sequence
        $previousJobs = $apiJob->getPreviousJobs();

        // Check if all previous jobs are completed
        if ($previousJobs->isNotEmpty() && $previousJobs->where('status', '!=', 'completed')->isNotEmpty()) {
            // If any previous job is not completed, reset this job to pending
            $apiJob->resetToPending();

            return;
        }

        // Proceed to execute the job
        return $next($job);
    }
}
