<?php

namespace Nidavellir\Mjolnir\Abstracts;

abstract class BaseQueuableJob extends BaseJob
{
    protected $startedAt;

    public $coreJobQueue;

    public function handle()
    {
        try {
            $this->coreJobQueue->updateToRunning();
            $this->coreJobQueue->startDuration();
            $this->compute();
            $this->coreJobQueue->updateToCompleted();
            $this->coreJobQueue->finalizeDuration();
        } catch (\Throwable $e) {
            $this->coreJobQueue->updateToFailed($e);
            $this->coreJobQueue->finalizeDuration();
            throw $e;
        }
    }

    abstract protected function compute();
}
