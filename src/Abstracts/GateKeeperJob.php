<?php

namespace Nidavellir\Mjolnir\Abstracts;

abstract class GateKeeperJob extends BaseJobQueuable
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
}
