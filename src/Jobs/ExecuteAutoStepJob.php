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

class ExecuteAutoStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

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
        return "auto-step-{$this->instance->id}-{$this->step->id}";
    }

    /**
     * Execute the job.
     */
    public function handle(AutoStepExecutor $executor): void
    {
        // Reload fresh instance to check current state
        $instance = $this->instance->fresh();

        // Skip if instance is no longer pending
        if ($instance->status !== 'pending') {
            Log::info('AutoStepJob skipped: instance no longer pending', [
                'instance_id' => $instance->id,
                'status' => $instance->status,
            ]);
            return;
        }

        // Skip if we're not at the expected step anymore
        if ($instance->current_step_id !== $this->step->id) {
            Log::info('AutoStepJob skipped: instance moved past step', [
                'instance_id' => $instance->id,
                'expected_step' => $this->step->id,
                'current_step' => $instance->current_step_id,
            ]);
            return;
        }

        // Execute the auto-step
        $result = $executor->execute($instance, $this->step, $this->user);

        Log::info('AutoStepJob completed', [
            'instance_id' => $instance->id,
            'step_id' => $this->step->id,
            'step_key' => $this->step->key,
            'result_status' => $result->status,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('AutoStepJob failed', [
            'instance_id' => $this->instance->id,
            'step_id' => $this->step->id,
            'step_key' => $this->step->key,
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // Update instance with error metadata
        $instance = $this->instance->fresh();
        if ($instance) {
            $metadata = $instance->metadata ?? [];
            $metadata['last_auto_step_error'] = [
                'step_id' => $this->step->id,
                'step_key' => $this->step->key,
                'error' => $exception?->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ];
            $instance->update(['metadata' => $metadata]);
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'dynaflow',
            'auto-step',
            "instance:{$this->instance->id}",
            "step:{$this->step->key}",
            "workflow:{$this->instance->dynaflow_id}",
        ];
    }
}
