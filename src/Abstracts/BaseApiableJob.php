<?php

namespace Nidavellir\Mjolnir\Abstracts;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Thor\Models\ApiJob;
use Nidavellir\Thor\Models\JobQueue;

abstract class BaseApiableJob extends BaseJob
{
    //public ApiJob $apiJob;

    //public ?JobQueue $jobQueue;

    protected Response $response;

    protected $startTime;

    protected $duration;

    /**
     * Middleware for the job.
     *
     * @return array
     */
    public function middleware()
    {
        return [new \Nidavellir\Mjolnir\Middleware\EnsureJobApiSequence];
    }

    /**
     * The main job handler that executes the compute method and manages timing.
     *
     * @return void
     */
    public function handle()
    {
        $this->apiJob->markAsRunning(gethostname());

        // Start the timer to measure execution duration
        $this->startTime = microtime(true);

        try {
            // Execute the specific job logic (to be defined in child classes)
            $response = $this->compute();

            if ($response) {
                $this->response = $response;
            }

            // After compute finishes, calculate the duration
            $this->calculateDuration();

            // Mark the job as completed
            $this->apiJob->markAsCompleted($this->response ?? null, $this->duration);

            // Dispatch the next jobs.
            ApiJob::dispatch();
        } catch (\Exception $e) {
            // Mark the job as failed in case of an exception
            $this->apiJob->markAsFailed($e);
            throw $e;
        }
    }

    /**
     * Abstract method for the specific job logic.
     * This must be implemented in each subclass.
     *
     * @return mixed
     */
    abstract protected function compute();

    /**
     * Calculate the duration of the job execution.
     */
    protected function calculateDuration()
    {
        $this->duration = microtime(true) - $this->startTime;
    }

    /**
     * Get the duration of the job execution.
     */
    public function getDuration()
    {
        return $this->duration;
    }
}
