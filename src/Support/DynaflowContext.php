<?php

namespace RSE\DynaFlow\Support;

use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;

class DynaflowContext
{
    public function __construct(
        public DynaflowInstance $instance,
        public DynaflowStep $targetStep,
        public string $decision,
        public mixed $user,
        public ?DynaflowStep $sourceStep = null,
        public ?DynaflowStepExecution $execution = null,
        public ?string $notes = null,
        public array $data = [],
        public bool $isBypassed = false,
    ) {
    }

    /**
     * Get the model being worked on
     */
    public function model(): mixed
    {
        return $this->instance->model;
    }

    /**
     * Get the pending changes data
     */
    public function pendingData(): array
    {
        return $this->instance->dynaflowData?->data ?? [];
    }

    /**
     * Get custom context value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if this is a transition (vs initial state)
     */
    public function isTransition(): bool
    {
        return $this->sourceStep !== null;
    }

    /**
     * Check if workflow completed (reached final step)
     */
    public function isCompleted(): bool
    {
        return $this->targetStep->is_final;
    }

    /**
     * Get the workflow topic
     */
    public function topic(): string
    {
        return $this->instance->dynaflow->topic;
    }

    /**
     * Get the workflow action
     */
    public function action(): string
    {
        return $this->instance->dynaflow->action;
    }

    /**
     * Get workflow status (after completion)
     */
    public function workflowStatus(): ?string
    {
        return $this->instance->status;
    }

    /**
     * Get transition duration in seconds
     */
    public function duration(): ?int
    {
        return $this->execution?->duration;
    }

    /**
     * Check if this workflow was auto-completed due to bypass exception
     */
    public function isBypassed(): bool
    {
        return $this->isBypassed;
    }
}
