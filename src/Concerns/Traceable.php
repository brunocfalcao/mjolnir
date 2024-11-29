<?php

namespace App\Concerns;

use App\Models\Trace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait Traceable
{
    protected ?Trace $currentTrace = null;

    // Internal array to store dynamically added traceable data
    protected array $_traceable = [];

    protected function initializeTraceable()
    {
        if (! isset($this->_traceable['group_uuid'])) {
            $this->_traceable['group_uuid'] = Str::uuid();
        }

        if (! isset($this->_traceable['hostname'])) {
            $this->_traceable['hostname'] = gethostname();
        }

        if (! isset($this->_traceable['class'])) {
            $this->_traceable['class'] = get_class($this);
        }

        // Always retrieve the latest arguments dynamically
        $this->_traceable['arguments'] = json_encode(get_object_vars($this));
    }

    public function trace(array $additionalData = [])
    {
        $this->initializeTraceable();

        // Calculate duration since the last trace (in milliseconds)
        $this->_traceable['duration'] = $this->calculateDuration();

        // Calculate total duration since the first trace (in milliseconds)
        $this->_traceable['total_duration'] = $this->calculateTotalDuration();

        // Include relatable if set
        if (isset($this->_traceable['related_id']) && isset($this->_traceable['related_type'])) {
            $relatedData = [
                'relatable_id' => $this->_traceable['related_id'],
                'relatable_type' => $this->_traceable['related_type'],
            ];
        } else {
            $relatedData = [];
        }

        // Merge dynamically set traceable data and additional data passed to trace()
        $traceData = array_merge($this->_traceable, $relatedData, $additionalData);

        // Create a new trace record (always creates a new line)
        $this->currentTrace = Trace::create($traceData)->model;

        // Clear specific non-persistent attributes after trace
        $this->clearNonPersistentAttributes();
    }

    public function retrace(array $data = [])
    {
        // Get the last trace for the current group_uuid
        $lastTrace = Trace::where('group_uuid', $this->_traceable['group_uuid'])->latest()->first();

        if (! $lastTrace) {
            throw new \Exception('No trace found to update. Ensure that trace() was called before retrace().');
        }

        // Always refresh arguments dynamically when retracing
        $this->_traceable['arguments'] = json_encode(get_object_vars($this));

        // Calculate duration since the last trace (in milliseconds)
        $this->_traceable['duration'] = $this->calculateDuration();

        // Calculate total duration since the first trace (in milliseconds)
        $this->_traceable['total_duration'] = $this->calculateTotalDuration();

        // Update the existing trace record
        $lastTrace->update(array_merge($this->_traceable, $data));
        $this->currentTrace = $lastTrace;

        // Clear specific non-persistent attributes after retrace
        $this->clearNonPersistentAttributes();
    }

    protected function calculateDuration(): float
    {
        // Get the application's timezone from config
        $appTimezone = config('app.timezone');

        // Get the last trace for the current group_uuid
        $lastTrace = Trace::where('group_uuid', $this->_traceable['group_uuid'])->latest()->first();

        // If no previous trace, set duration to 0
        if (! $lastTrace) {
            return 0;
        }

        // Parse the last trace created_at to Carbon instance with the application's timezone
        $lastTraceTime = Carbon::parse($lastTrace->created_at)->setTimezone($appTimezone);

        // Calculate the difference in milliseconds between the current time and the last trace
        return $lastTraceTime->diffInMilliseconds(Carbon::now()->setTimezone($appTimezone));
    }

    protected function calculateTotalDuration(): float
    {
        // Get the duration sum from the existing traces in the database
        $existingTotalDuration = Trace::where('group_uuid', $this->_traceable['group_uuid'])->sum('duration');

        // Add the duration of the current trace to the total duration
        return $existingTotalDuration + ($this->_traceable['duration'] ?? 0);
    }

    // Setters for traceable data (handled dynamically via __call magic method)
    public function withRelatable(Model $model)
    {
        $this->_traceable['related_id'] = $model->getKey();
        $this->_traceable['related_type'] = get_class($model);

        return $this;
    }

    // Handle any dynamic trace attributes via __call magic method
    public function __call($method, $arguments)
    {
        if (strpos($method, 'with') == 0) {
            $attribute = lcfirst(substr($method, 4));
            $this->_traceable[$attribute] = $arguments[0];

            return $this;
        }

        throw new \BadMethodCallException("Method {$method} does not exist.");
    }

    // Reset function to clear dynamically set traceable data after each trace, but retain persistent data
    protected function clearNonPersistentAttributes()
    {
        // Clear only the non-persistent fields
        unset(
            $this->_traceable['canonical'],
            $this->_traceable['description'],
            $this->_traceable['duration'],
            $this->_traceable['total_duration'],
            $this->_traceable['status'],
            $this->_traceable['error_message']
        );
    }

    // Reset function to clear all traceable attributes except persistent ones
    protected function resetTraceAttributes(bool $clearCurrentTrace = true)
    {
        // Reset attributes but preserve group_uuid, class, hostname, and related model details
        $this->_traceable = array_merge($this->_traceable, [
            'group_uuid' => $this->_traceable['group_uuid'] ?? null, // Keep group UUID
            'hostname' => $this->_traceable['hostname'] ?? null,   // Keep hostname
            'class' => $this->_traceable['class'] ?? null,      // Keep class
            'related_id' => $this->_traceable['related_id'] ?? null, // Keep related ID
            'related_type' => $this->_traceable['related_type'] ?? null, // Keep related type
        ]);

        // Remove dynamic attributes after use
        $this->clearNonPersistentAttributes();

        if ($clearCurrentTrace) {
            $this->currentTrace = null; // Clear current trace if specified
        }
    }
}
