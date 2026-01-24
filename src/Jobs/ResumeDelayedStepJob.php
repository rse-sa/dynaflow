<?php

namespace RSE\DynaFlow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\AutoStepExecutor;
use Throwable;

class ResumeDelayedStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(
        public DynaflowInstance $instance,
        public DynaflowStep $step,
        public mixed $user = null
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "resume-delayed-{$this->instance->id}-{$this->step->id}";
    }

    /**
     * Execute the job.
     */
    public function handle(AutoStepExecutor $executor): void
    {
        // Reload fresh instance
        $instance = $this->instance->fresh();

        // Skip if instance is no longer pending
        if ($instance->status !== 'pending') {
            Log::info('ResumeDelayedStepJob skipped: instance no longer pending', [
                'instance_id' => $instance->id,
                'status' => $instance->status,
            ]);
            return;
        }

        // Verify the instance is still waiting at this step
        $metadata = $instance->metadata ?? [];
        $waitingStepId = $metadata['waiting_for_delay']['step_id'] ?? null;

        if ($waitingStepId !== $this->step->id) {
            Log::info('ResumeDelayedStepJob skipped: instance not waiting for this step', [
                'instance_id' => $instance->id,
                'expected_step' => $this->step->id,
                'waiting_step' => $waitingStepId,
            ]);
            return;
        }

        // Clear the waiting metadata
        unset($metadata['waiting_for_delay']);
        $instance->update(['metadata' => $metadata]);

        // Get the next step from the delay step's transitions
        $nextStep = $this->step->allowedTransitions()->first();

        if (! $nextStep) {
            Log::warning('ResumeDelayedStepJob: No transition found from delay step', [
                'instance_id' => $instance->id,
                'step_id' => $this->step->id,
            ]);
            return;
        }

        // Update to the next step
        $instance->update(['current_step_id' => $nextStep->id]);

        // If next step is also auto-executable, continue the chain
        if ($nextStep->isAutoExecutable()) {
            ExecuteAutoStepJob::dispatch($instance->fresh(), $nextStep, $this->user);
        }

        Log::info('ResumeDelayedStepJob completed', [
            'instance_id' => $instance->id,
            'delay_step' => $this->step->key,
            'next_step' => $nextStep->key,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ResumeDelayedStepJob failed', [
            'instance_id' => $this->instance->id,
            'step_id' => $this->step->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'dynaflow',
            'resume-delayed',
            "instance:{$this->instance->id}",
            "step:{$this->step->key}",
        ];
    }
}
