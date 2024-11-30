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

    public $apiJob;

    protected $response;

    protected $startTime;

    protected $duration;

    public function __construct(ApiJob $apiJob)
    {
        $this->apiJob = $apiJob;
    }

    /**
     * Middleware for the job.
     *
     * @return array
     */
    public function middleware()
    {
        return [new \Nidavellir\Mjolnir\Middleware\EnsureJobSequence];
    }

    /**
     * The main job handler that executes the compute method and manages timing.
     *
     * @return void
     */
    public function handle()
    {
        $this->apiJob->markAsRunning(gethostname());
        info('Executing '.get_class($this));

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
            info(get_class($this).' completed');
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

    /**
     * Get the response data. Defaults to null if not set.
     */
    public function getResponse()
    {
        return $this->response ?? null;
    }
}
