<?php

namespace RSE\DynaFlow\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Enums\DynaflowStatus;
use RSE\DynaFlow\Events\DynaflowCancelled;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\DynaflowStarted;
use RSE\DynaFlow\Events\StepTransitioned;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowData;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Notifications\DynaflowStepNotification;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowEngine
{
    public function __construct(
        protected DynaflowValidator $validator,
        protected DynaflowHookManager $hookManager
    ) {}

    /**
     * @throws \Throwable
     */
    public function trigger(string $topic, string $action, ?Model $model, array $data, $user): mixed
    {
        $workflow = Dynaflow::where('topic', $topic)
            ->where('action', $action)
            ->where('active', true)
            ->first();

        if (! $workflow) {
            return $this->applyDirectly($topic, $action, $model, $data, $user);
        }

        if ($this->validator->shouldBypassDynaflow($workflow, $user)) {
            return $this->applyDirectly($topic, $action, $model, $data, $user);
        }

        // Check field-based filtering (monitored_fields / ignored_fields)
        if ($model && $model->exists && $action === 'update') {
            if (! $this->shouldTriggerBasedOnFields($workflow, $model, $data)) {
                return $this->applyDirectly($topic, $action, $model, $data, $user);
            }
        }

        // Run beforeTrigger hooks - can skip workflow by returning false
        if (! $this->hookManager->runBeforeTriggerHooks($workflow, $model, $data, $user)) {
            return $this->applyDirectly($topic, $action, $model, $data, $user);
        }

        return DB::transaction(function () use ($workflow, $model, $data, $user) {
            // Check for duplicates only if model exists
            if ($model && $model->exists) {
                $duplicateInstance = $this->validator->getActiveDuplicateInstance($workflow, $model);

                $duplicateInstance?->update(['status' => DynaflowStatus::CANCELLED->value]);
            }

            $instance = DynaflowInstance::create([
                'dynaflow_id'       => $workflow->id,
                'model_type'        => $model?->getMorphClass(),
                'model_id'          => $model?->getKey(),
                'status'            => DynaflowStatus::PENDING->value,
                'triggered_by_type' => $user->getMorphClass(),
                'triggered_by_id'   => $user->getKey(),
                'current_step_id'   => $workflow->steps->first()?->id,
                'step_started_at'   => now(),
            ]);

            DynaflowData::create([
                'dynaflow_instance_id' => $instance->id,
                'data'                 => $data,
                'applied'              => false,
            ]);

            $this->hookManager->runAfterTriggerHooks($workflow, $instance, $model, $user);

            event(new DynaflowStarted($instance));

            return $instance;
        });
    }

    /**
     * Transition to target step
     * Automatically completes workflow if target step is final
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function transitionTo(
        DynaflowInstance $instance,
        DynaflowStep $targetStep,
        mixed $user,
        string $decision,
        ?string $notes = null,
        array $context = []
    ): DynaflowContext {
        if (! $instance->isPending()) {
            throw new Exception('Dynaflow instance is not pending');
        }

        $sourceStep = $instance->currentStep;

        if (! $this->validator->canUserExecuteStep($sourceStep, $user)) {
            throw new Exception('User not authorized to execute this step');
        }

        if (! $sourceStep->canTransitionTo($targetStep)) {
            throw new Exception('Invalid step transition');
        }

        // Create context object
        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $targetStep,
            decision: $decision,
            user: $user,
            sourceStep: $sourceStep,
            execution: null,
            notes: $notes,
            data: $context
        );

        // Run beforeTransitionTo hooks (can block)
        if (! $this->hookManager->runBeforeTransitionToHooks($ctx)) {
            throw new Exception('Step execution blocked by hook');
        }

        // Run transition hooks (can block)
        if (! $this->hookManager->runTransitionHooks($ctx)) {
            throw new Exception('Transition blocked by hook');
        }

        return DB::transaction(function () use ($instance, $targetStep, $ctx) {
            // Calculate duration
            $durationSeconds = $this->calculateDuration($instance, $ctx->sourceStep);

            // Create execution record
            $execution = DynaflowStepExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'dynaflow_step_id'     => $ctx->sourceStep->id,
                'executed_by_type'     => $ctx->user->getMorphClass(),
                'executed_by_id'       => $ctx->user->getKey(),
                'decision'             => $ctx->decision,
                'note'                 => $ctx->notes,
                'duration'             => (int) ($durationSeconds / 60), // Convert to minutes for storage
                'execution_started_at' => $instance->step_started_at,
                'executed_at'          => now(),
            ]);

            // Update context with execution
            $ctx->execution = $execution;

            // Send notifications if enabled
            $this->sendStepNotifications($ctx);

            // Update instance state
            $instance->update([
                'current_step_id' => $targetStep->id,
                'step_started_at' => now(),
            ]);

            // Check if workflow should end
            if ($targetStep->is_final) {
                $this->completeWorkflow($instance, $ctx);
            } else {
                // Fire afterTransitionTo hook
                $this->hookManager->runAfterTransitionToHooks($ctx);
            }

            event(new StepTransitioned($ctx));

            return $ctx;
        });
    }

    /**
     * Cancel workflow explicitly
     * Can be called from any step
     *
     * @throws \Exception|\Throwable
     */
    public function cancelWorkflow(
        DynaflowInstance $instance,
        mixed $user,
        string $decision,
        ?string $notes = null,
        array $context = []
    ): DynaflowContext {
        if (! $instance->isPending()) {
            throw new Exception('Dynaflow instance is not pending');
        }

        $sourceStep = $instance->currentStep;

        // Create context
        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $sourceStep, // No target, staying at current
            decision: $decision,
            user: $user,
            sourceStep: $sourceStep,
            notes: $notes,
            data: $context
        );

        return DB::transaction(function () use ($instance, $ctx, $context) {
            // Calculate duration
            $durationSeconds = $this->calculateDuration($instance, $ctx->sourceStep);

            // Create execution record for audit
            $execution = DynaflowStepExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'dynaflow_step_id'     => $ctx->sourceStep->id,
                'executed_by_type'     => $ctx->user->getMorphClass(),
                'executed_by_id'       => $ctx->user->getKey(),
                'decision'             => $ctx->decision,
                'note'                 => $ctx->notes,
                'duration'             => (int) ($durationSeconds / 60),
                'execution_started_at' => $instance->step_started_at,
                'executed_at'          => now(),
            ]);

            $ctx->execution = $execution;

            // Update instance
            $status = $context['status'] ?? $ctx->decision;
            $instance->update([
                'status'       => $status,
                'cancelled_at' => now(),
            ]);

            // Fire cancellation hooks
            $this->hookManager->runCancelHooks($ctx);

            event(new DynaflowCancelled($ctx));

            return $ctx;
        });
    }

    /**
     * Complete workflow normally (via final step)
     */
    protected function completeWorkflow(
        DynaflowInstance $instance,
        DynaflowContext $ctx
    ): void {
        // Status priority: step config > decision
        $status = $ctx->targetStep->workflow_status ?? $ctx->decision;

        $instance->update([
            'status'       => $status,
            'completed_at' => now(),
        ]);

        // Mark data as applied (before hooks so hooks can check this)
        $instance->dynaflowData?->update(['applied' => true]);

        // Fire completion hooks with FULL context
        $this->hookManager->runCompleteHooks($ctx);

        event(new DynaflowCompleted($ctx));
    }

    /**
     * Calculate duration in seconds since last execution or instance creation
     */
    protected function calculateDuration(
        DynaflowInstance $instance,
        ?DynaflowStep $currentStep
    ): int {
        $lastExecution = $instance->executions()
            ->where('dynaflow_step_id', $currentStep?->id)
            ->latest('executed_at')
            ->first();

        $startTime = $lastExecution?->executed_at ?? $instance->step_started_at ?? $instance->created_at;

        return now()->diffInSeconds($startTime, true);
    }

    /**
     * Check if workflow should be triggered based on field filtering rules.
     */
    protected function shouldTriggerBasedOnFields(Dynaflow $workflow, Model $model, array $data): bool
    {
        // Get original data from model
        $originalData = $model->only(array_keys($data));

        return $workflow->shouldTriggerForFields($originalData, $data);
    }

    /**
     * Apply changes directly without workflow (no workflow configured or user has exception).
     * Executes completion hooks to perform the actual action.
     *
     * @return mixed The result from the completion hook (usually the model)
     */
    protected function applyDirectly(string $topic, string $action, ?Model $model, array $data, mixed $user): mixed
    {
        // Create a temporary instance for the hook to access data
        $tempInstance = new DynaflowInstance([
            'dynaflow_id' => null,
            'model_type'  => $model?->getMorphClass(),
            'model_id'    => $model?->getKey(),
            'status'      => DynaflowStatus::COMPLETED->value,
        ]);

        // Attach a temporary dynaflowData relation
        $tempData = new DynaflowData(['data' => $data]);
        $tempInstance->setRelation('dynaflowData', $tempData);
        $tempInstance->setRelation('model', $model);

        // Store topic and action temporarily
        $tempWorkflow = new Dynaflow(['topic' => $topic, 'action' => $action]);
        $tempInstance->setRelation('dynaflow', $tempWorkflow);

        // Create a temporary final step for context
        $tempStep = new DynaflowStep([
            'name'            => 'Direct Application',
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        // Create context for direct application
        $ctx = new DynaflowContext(
            instance: $tempInstance,
            targetStep: $tempStep,
            decision: 'approved', // Direct application is considered approved
            user: $user,
            sourceStep: null,
            execution: null,
            notes: 'Direct application - no workflow',
            data: []
        );

        // Run completion hooks - they will perform the actual action
        $this->hookManager->runCompleteHooks($ctx);

        // Return the model (or result from hook)
        return $tempInstance->model ?? $model;
    }

    /**
     * Send step notifications based on metadata settings
     */
    protected function sendStepNotifications(DynaflowContext $ctx): void
    {
        $sourceStep = $ctx->sourceStep;

        if (! $sourceStep) {
            return;
        }

        // Determine which notification setting to check based on decision
        $notificationKey = match (strtolower($ctx->decision)) {
            'approved', 'approve' => 'notify_on_approve',
            'rejected', 'reject' => 'notify_on_reject',
            'request_edit', 'edit' => 'notify_on_edit_request',
            default => null,
        };

        // Check if notifications are enabled for this decision type
        if ($notificationKey && $sourceStep->getMetadata($notificationKey)) {
            // Get assignees for the source step
            $assignees = $sourceStep->assignees()
                ->with('assignable')
                ->get()
                ->pluck('assignable')
                ->filter();

            if ($assignees->isNotEmpty()) {
                Notification::send($assignees, new DynaflowStepNotification($ctx));
            }
        }
    }
}
