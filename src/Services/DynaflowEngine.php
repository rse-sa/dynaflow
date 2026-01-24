<?php

namespace RSE\DynaFlow\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Enums\BypassMode;
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
        protected DynaflowHookManager $hookManager,
        protected ?AutoStepExecutor $autoStepExecutor = null
    ) {
        // Auto-resolve if not injected (for backward compatibility)
        $this->autoStepExecutor ??= app(AutoStepExecutor::class);
    }

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
            $bypassMode = $workflow->getBypassMode();

            return match ($bypassMode) {
                BypassMode::DIRECT_COMPLETE->value => $this->triggerWithDirectComplete($workflow, $model, $data, $user),
                BypassMode::AUTO_FOLLOW->value     => $this->triggerWithAutoFollow($workflow, $model, $data, $user),
                BypassMode::CUSTOM_STEPS->value    => $this->triggerWithCustomSteps($workflow, $model, $data, $user),
                default                            => $this->applyDirectly($topic, $action, $model, $data, $user),
            };
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

            // Get the first step
            $firstStep = $workflow->steps->first();

            if ($firstStep) {
                // Run step activated hooks
                $this->hookManager->runStepActivatedHooks($instance, $firstStep, $user);

                // Trigger auto-execution if first step is auto-executable
                if ($firstStep->isAutoExecutable()) {
                    $this->autoStepExecutor->execute($instance, $firstStep, $user);
                }
            }

            return $instance;
        });
    }

    /**
     * Trigger workflow with direct completion (bypass mode: direct_complete)
     * Jump directly to final step, create single execution record
     *
     * @throws \Throwable
     */
    protected function triggerWithDirectComplete(
        Dynaflow $workflow,
        ?Model $model,
        array $data,
        mixed $user
    ): DynaflowInstance {
        return DB::transaction(function () use ($workflow, $model, $data, $user) {
            // Cancel duplicates
            if ($model && $model->exists) {
                $this->validator->getActiveDuplicateInstance($workflow, $model)
                    ?->update(['status' => DynaflowStatus::CANCELLED->value]);
            }

            // Get final step
            $finalStep = $workflow->steps()->where('is_final', true)->first();
            if (! $finalStep) {
                throw new Exception("Workflow '" . $workflow->name . "' has no final step for direct_complete mode");
            }

            // Create instance
            $instance = DynaflowInstance::create([
                'dynaflow_id'       => $workflow->id,
                'model_type'        => $model?->getMorphClass(),
                'model_id'          => $model?->getKey(),
                'status'            => DynaflowStatus::PENDING->value,
                'triggered_by_type' => $user->getMorphClass(),
                'triggered_by_id'   => $user->getKey(),
                'current_step_id'   => $finalStep->id,
                'step_started_at'   => now(),
            ]);

            DynaflowData::create([
                'dynaflow_instance_id' => $instance->id,
                'data'                 => $data,
                'applied'              => false,
            ]);

            event(new DynaflowStarted($instance));

            // Create context for final step
            $ctx = new DynaflowContext(
                instance: $instance,
                targetStep: $finalStep,
                decision: 'auto_approved',
                user: $user,
                sourceStep: null,
                execution: null,
                notes: 'Auto-approved via bypass (direct_complete)',
                data: [],
                isBypassed: true
            );

            // Run beforeTransitionTo hooks (can block)
            if (! $this->hookManager->runBeforeTransitionToHooks($ctx)) {
                throw new Exception("Bypass blocked by beforeTransitionTo hook for step '" . $finalStep->key . "'");
            }

            // Create execution record
            $execution = DynaflowStepExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'dynaflow_step_id'     => $finalStep->id,
                'executed_by_type'     => $user->getMorphClass(),
                'executed_by_id'       => $user->getKey(),
                'decision'             => 'auto_approved',
                'note'                 => 'Auto-approved via bypass (direct_complete)',
                'bypassed'             => true,
                'duration'             => 0,
                'execution_started_at' => now(),
                'executed_at'          => now(),
            ]);

            $ctx->execution = $execution;

            // Run afterTransitionTo hooks
            $this->hookManager->runAfterTransitionToHooks($ctx);

            // Complete workflow
            $this->completeWorkflow($instance, $ctx);

            return $instance;
        });
    }

    /**
     * Trigger workflow with auto-follow (bypass mode: auto_follow)
     * Follow complete linear workflow path step-by-step
     *
     * @throws \Throwable
     */
    protected function triggerWithAutoFollow(
        Dynaflow $workflow,
        ?Model $model,
        array $data,
        mixed $user
    ): DynaflowInstance {
        return DB::transaction(function () use ($workflow, $model, $data, $user) {
            // Validate workflow is linear
            if (! $workflow->isLinear()) {
                throw new Exception(
                    "Workflow '" . $workflow->name . "' has branching paths. " .
                    'Cannot use auto_follow mode. Use custom_steps mode instead.'
                );
            }

            // Cancel duplicates
            if ($model && $model->exists) {
                $this->validator->getActiveDuplicateInstance($workflow, $model)
                    ?->update(['status' => DynaflowStatus::CANCELLED->value]);
            }

            // Build linear path
            $firstStep = $workflow->steps()->orderBy('order')->first();
            $stepPath  = $this->buildLinearPath($firstStep);

            if (empty($stepPath)) {
                throw new Exception("Workflow '" . $workflow->name . "' has no steps");
            }

            // Create instance
            $instance = DynaflowInstance::create([
                'dynaflow_id'       => $workflow->id,
                'model_type'        => $model?->getMorphClass(),
                'model_id'          => $model?->getKey(),
                'status'            => DynaflowStatus::PENDING->value,
                'triggered_by_type' => $user->getMorphClass(),
                'triggered_by_id'   => $user->getKey(),
                'current_step_id'   => $firstStep->id,
                'step_started_at'   => now(),
            ]);

            DynaflowData::create([
                'dynaflow_instance_id' => $instance->id,
                'data'                 => $data,
                'applied'              => false,
            ]);

            event(new DynaflowStarted($instance));

            // Execute each step with hooks
            $previousStep  = null;
            $lastExecution = null;

            foreach ($stepPath as $step) {
                // Create context for this step
                $ctx = new DynaflowContext(
                    instance: $instance,
                    targetStep: $step,
                    decision: 'auto_approved',
                    user: $user,
                    sourceStep: $previousStep,
                    execution: null,
                    notes: 'Auto-approved via bypass (auto_follow)',
                    data: [],
                    isBypassed: true
                );

                // Run beforeTransitionTo hooks (can block)
                if (! $this->hookManager->runBeforeTransitionToHooks($ctx)) {
                    throw new Exception("Bypass blocked by beforeTransitionTo hook for step '" . $step->key . "'");
                }

                // Run onTransition hooks if there's a source step (from -> to)
                if ($previousStep) {
                    if (! $this->hookManager->runTransitionHooks($ctx)) {
                        throw new Exception("Bypass blocked by onTransition hook: '" . $previousStep->key . "' -> '" . $step->key . "'");
                    }
                }

                // Create execution record
                $execution = DynaflowStepExecution::create([
                    'dynaflow_instance_id' => $instance->id,
                    'dynaflow_step_id'     => $step->id,
                    'executed_by_type'     => $user->getMorphClass(),
                    'executed_by_id'       => $user->getKey(),
                    'decision'             => 'auto_approved',
                    'note'                 => 'Auto-approved via bypass (auto_follow)',
                    'bypassed'             => true,
                    'duration'             => 0,
                    'execution_started_at' => now(),
                    'executed_at'          => now(),
                ]);

                $ctx->execution = $execution;

                // Run afterTransitionTo hooks
                $this->hookManager->runAfterTransitionToHooks($ctx);

                $previousStep  = $step;
                $lastExecution = $execution;
            }

            // Get final step for completion (last step in path)
            $finalStep = collect($stepPath)->last();
            $ctx       = new DynaflowContext(
                instance: $instance,
                targetStep: $finalStep,
                decision: 'auto_approved',
                user: $user,
                sourceStep: null,
                execution: $lastExecution,
                notes: 'Auto-approved via bypass (auto_follow)',
                data: [],
                isBypassed: true
            );

            $this->completeWorkflow($instance, $ctx);

            return $instance;
        });
    }

    /**
     * Trigger workflow with custom steps (bypass mode: custom_steps)
     * Follow developer-specified steps from metadata
     *
     * @throws \Throwable
     */
    protected function triggerWithCustomSteps(
        Dynaflow $workflow,
        ?Model $model,
        array $data,
        mixed $user
    ): DynaflowInstance {
        return DB::transaction(function () use ($workflow, $model, $data, $user) {
            $customStepKeys = $workflow->getBypassSteps();

            if (empty($customStepKeys)) {
                throw new Exception(
                    "Workflow '" . $workflow->name . "' uses custom_steps mode but no steps defined in metadata.bypass.steps"
                );
            }

            // Resolve step keys to actual steps
            $steps = [];
            foreach ($customStepKeys as $stepKey) {
                $step = $workflow->steps()->where('key', $stepKey)->first();
                if (! $step) {
                    throw new Exception("Step '" . $stepKey . "' not found in workflow '" . $workflow->name . "'");
                }
                $steps[] = $step;
            }

            // Validate final step is included
            $lastStep = end($steps);
            if (! $lastStep->is_final) {
                throw new Exception(
                    'Last step in metadata.bypass.steps must be a final step. ' .
                    "Got '" . $lastStep->key . "' which is not final."
                );
            }

            // Cancel duplicates
            if ($model && $model->exists) {
                $this->validator->getActiveDuplicateInstance($workflow, $model)
                    ?->update(['status' => DynaflowStatus::CANCELLED->value]);
            }

            // Create instance
            $instance = DynaflowInstance::create([
                'dynaflow_id'       => $workflow->id,
                'model_type'        => $model?->getMorphClass(),
                'model_id'          => $model?->getKey(),
                'status'            => DynaflowStatus::PENDING->value,
                'triggered_by_type' => $user->getMorphClass(),
                'triggered_by_id'   => $user->getKey(),
                'current_step_id'   => $steps[0]->id,
                'step_started_at'   => now(),
            ]);

            DynaflowData::create([
                'dynaflow_instance_id' => $instance->id,
                'data'                 => $data,
                'applied'              => false,
            ]);

            event(new DynaflowStarted($instance));

            // Execute each custom step with hooks
            $previousStep  = null;
            $lastExecution = null;

            foreach ($steps as $step) {
                // Create context for this step
                $ctx = new DynaflowContext(
                    instance: $instance,
                    targetStep: $step,
                    decision: 'auto_approved',
                    user: $user,
                    sourceStep: $previousStep,
                    execution: null,
                    notes: 'Auto-approved via bypass (custom_steps)',
                    data: [],
                    isBypassed: true
                );

                // Run beforeTransitionTo hooks (can block)
                if (! $this->hookManager->runBeforeTransitionToHooks($ctx)) {
                    throw new Exception("Bypass blocked by beforeTransitionTo hook for step '" . $step->key . "'");
                }

                // Run onTransition hooks if there's a source step (from -> to)
                if ($previousStep) {
                    if (! $this->hookManager->runTransitionHooks($ctx)) {
                        throw new Exception("Bypass blocked by onTransition hook: '" . $previousStep->key . "' -> '" . $step->key . "'");
                    }
                }

                // Create execution record
                $execution = DynaflowStepExecution::create([
                    'dynaflow_instance_id' => $instance->id,
                    'dynaflow_step_id'     => $step->id,
                    'executed_by_type'     => $user->getMorphClass(),
                    'executed_by_id'       => $user->getKey(),
                    'decision'             => 'auto_approved',
                    'note'                 => 'Auto-approved via bypass (custom_steps)',
                    'bypassed'             => true,
                    'duration'             => 0,
                    'execution_started_at' => now(),
                    'executed_at'          => now(),
                ]);

                $ctx->execution = $execution;

                // Run afterTransitionTo hooks
                $this->hookManager->runAfterTransitionToHooks($ctx);

                $previousStep  = $step;
                $lastExecution = $execution;
            }

            // Get final step for completion
            $finalStep = $lastStep;
            $ctx       = new DynaflowContext(
                instance: $instance,
                targetStep: $finalStep,
                decision: 'auto_approved',
                user: $user,
                sourceStep: null,
                execution: $lastExecution,
                notes: 'Auto-approved via bypass (custom_steps)',
                data: [],
                isBypassed: true
            );

            $this->completeWorkflow($instance, $ctx);

            return $instance;
        });
    }

    /**
     * Build linear path from first to final step
     *
     * @return DynaflowStep[]
     */
    protected function buildLinearPath(DynaflowStep $firstStep): array
    {
        $path        = [];
        $currentStep = $firstStep;

        while ($currentStep) {
            $path[] = $currentStep;

            if ($currentStep->is_final) {
                break;
            }

            // Get next step (only one allowed in linear workflow)
            $currentStep = $currentStep->allowedTransitions()->first();
        }

        return $path;
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

                // Reload instance to get fresh state
                $instance = $instance->fresh();

                // Run step activated hooks for the new step
                $this->hookManager->runStepActivatedHooks($instance, $targetStep, $ctx->user);

                // Trigger auto-execution if next step is auto-executable
                if ($targetStep->isAutoExecutable()) {
                    $this->autoStepExecutor->execute($instance, $targetStep, $ctx->user);
                }
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
