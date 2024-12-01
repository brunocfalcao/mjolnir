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

        // Api Job has an index?
        if (! is_null($apiJob->index)) {
            // Retrieve all previous jobs in the sequence
            $previousJobs = $apiJob->getPreviousJobs();

            // Check if any previous job has failed
            $failedJobs = $previousJobs->where('status', 'failed');

            if ($failedJobs->isNotEmpty()) {
                // If any previous job has failed, mark the following jobs in this block as "stopped"
                ApiJob::where('block_uuid', $apiJob->block_uuid)
                    ->where('index', '>=', $apiJob->index)
                    ->update(['status' => 'stopped']);

                return; // Stop execution and prevent this job from running
            }

            // Check if all previous jobs are completed
            if ($previousJobs->isNotEmpty() && $previousJobs->where('status', '!=', 'completed')->isNotEmpty()) {
                // If any previous job is not completed, reset this job to pending
                $apiJob->resetToPending();

                return;
            }
        }

        // Proceed to execute the job
        return $next($job);
    }
}
