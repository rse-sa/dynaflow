<?php

namespace RSE\DynaFlow\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Enums\DynaflowStatus;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\StepTransitioned;
use RSE\DynaFlow\Jobs\ExecuteAutoStepJob;
use RSE\DynaFlow\Jobs\ResumeDelayedStepJob;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Orchestrates auto-execution of stateless steps.
 *
 * When a workflow transitions to an auto-executable step, this service
 * handles executing the step's action handler and determining what happens next.
 *
 * Execution flow:
 * 1. Step becomes current (via trigger or transition)
 * 2. Check if step is auto-executable
 * 3. Run action handler
 * 4. Based on result:
 *    - Success/Route: Transition to next step
 *    - Waiting: Schedule resume job
 *    - Failed: Handle error
 *    - Forked: Handle parallel branches
 * 5. If next step is also auto-executable, repeat
 */
class AutoStepExecutor
{
    /**
     * Maximum auto-execution chain length to prevent infinite loops.
     */
    protected const MAX_CHAIN_LENGTH = 100;

    public function __construct(
        protected ActionHandlerRegistry $registry,
        protected DynaflowHookManager $hookManager
    ) {}

    /**
     * Execute an auto-executable step.
     *
     * @param  DynaflowInstance  $instance  The workflow instance
     * @param  DynaflowStep  $step  The step to execute
     * @param  mixed  $user  The user context (usually the triggering user)
     * @param  bool  $chainExecution  Whether to chain to next auto-steps
     * @return ActionResult The execution result
     */
    public function execute(
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user,
        bool $chainExecution = true
    ): ActionResult {
        // Safety check
        if (! $step->isAutoExecutable()) {
            return ActionResult::failed('Step is not auto-executable', [
                'step_type' => $step->type,
            ]);
        }

        // Check instance is still pending
        if (! $instance->isPending()) {
            return ActionResult::failed('Workflow instance is no longer pending');
        }

        // Create context for this execution
        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $step,
            decision: 'auto_executed',
            user: $user,
            sourceStep: null,
            execution: null,
            notes: "Auto-executed by {$step->type} handler",
            data: []
        );

        // Run beforeTransitionTo hooks (can block)
        if (! $this->hookManager->runBeforeTransitionToHooks($ctx)) {
            return ActionResult::failed('Execution blocked by beforeTransitionTo hook');
        }

        return DB::transaction(function () use ($instance, $step, $user, $ctx, $chainExecution) {
            // Execute the action handler
            $result = $this->registry->execute($step, $ctx);

            // Create execution record
            $execution = DynaflowStepExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'dynaflow_step_id'     => $step->id,
                'executed_by_type'     => $user->getMorphClass(),
                'executed_by_id'       => $user->getKey(),
                'decision'             => $result->getStatus(),
                'note'                 => json_encode($result->getData()),
                'duration'             => 0,
                'execution_started_at' => now(),
                'executed_at'          => now(),
            ]);

            $ctx->execution = $execution;

            // Handle result based on status
            return $this->handleResult($result, $instance, $step, $user, $ctx, $chainExecution);
        });
    }

    /**
     * Handle the action result and determine next steps.
     */
    protected function handleResult(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user,
        DynaflowContext $ctx,
        bool $chainExecution
    ): ActionResult {
        // Run afterTransitionTo hooks
        $this->hookManager->runAfterTransitionToHooks($ctx);

        // Fire event
        event(new StepTransitioned($ctx));

        // Handle based on result status
        return match ($result->getStatus()) {
            ActionResult::STATUS_SUCCESS  => $this->handleSuccess($result, $instance, $step, $user, $chainExecution),
            ActionResult::STATUS_ROUTE_TO => $this->handleRouting($result, $instance, $step, $user, $chainExecution),
            ActionResult::STATUS_WAITING  => $this->handleWaiting($result, $instance, $step, $user),
            ActionResult::STATUS_FORKED   => $this->handleForked($result, $instance, $step, $user),
            ActionResult::STATUS_FAILED   => $this->handleFailure($result, $instance, $step),
            default                       => $result,
        };
    }

    /**
     * Handle successful execution - move to next step.
     */
    protected function handleSuccess(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user,
        bool $chainExecution
    ): ActionResult {
        // Get first allowed transition
        $nextStep = $step->allowedTransitions()->first();

        if (! $nextStep) {
            // No next step - check if this is final
            if ($step->is_final) {
                return $this->completeWorkflow($instance, $step, $result);
            }

            return ActionResult::failed('No next step available and step is not final');
        }

        return $this->transitionToNext($instance, $nextStep, $user, $result, $chainExecution);
    }

    /**
     * Handle routing result - move to specified step.
     */
    protected function handleRouting(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user,
        bool $chainExecution
    ): ActionResult {
        $targetKey = $result->getRoute();

        // Find target step
        $nextStep = $step->allowedTransitions()
            ->where('key', $targetKey)
            ->first();

        if (! $nextStep) {
            // Try to find by ID or name
            $nextStep = $instance->dynaflow->steps()
                ->where('key', $targetKey)
                ->orWhere('id', $targetKey)
                ->first();
        }

        if (! $nextStep) {
            return ActionResult::failed("Target step '{$targetKey}' not found");
        }

        return $this->transitionToNext($instance, $nextStep, $user, $result, $chainExecution);
    }

    /**
     * Handle waiting result - schedule resume job.
     */
    protected function handleWaiting(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user
    ): ActionResult {
        $resumeAt = $result->get('resume_at');

        if ($resumeAt) {
            // Schedule job to resume after delay
            ResumeDelayedStepJob::dispatch($instance, $step, $user)
                ->delay(now()->parse($resumeAt));

            Log::info("Scheduled workflow resume for instance {$instance->id} at {$resumeAt}");
        }

        // Update instance to note it's waiting
        $instance->update([
            'metadata' => array_merge($instance->metadata ?? [], [
                'waiting_at'  => $step->id,
                'waiting_for' => $result->get('waiting_for'),
                'resume_at'   => $resumeAt,
            ]),
        ]);

        return $result;
    }

    /**
     * Handle forked result - parallel branches were created.
     */
    protected function handleForked(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step,
        mixed $user
    ): ActionResult {
        // Parallel handler should have already dispatched branch jobs
        Log::info("Workflow {$instance->id} forked into branches", $result->getData());

        return $result;
    }

    /**
     * Handle failure result.
     */
    protected function handleFailure(
        ActionResult $result,
        DynaflowInstance $instance,
        DynaflowStep $step
    ): ActionResult {
        Log::error("Auto-step execution failed", [
            'instance_id' => $instance->id,
            'step_id'     => $step->id,
            'step_key'    => $step->key,
            'error'       => $result->getError(),
            'data'        => $result->getData(),
        ]);

        // Check if step has error handling configured
        $errorRoute = $step->getActionConfig('on_error_route');
        if ($errorRoute) {
            $errorStep = $instance->dynaflow->steps()->where('key', $errorRoute)->first();
            if ($errorStep) {
                $instance->update([
                    'current_step_id' => $errorStep->id,
                    'step_started_at' => now(),
                ]);

                return ActionResult::routeTo($errorRoute, [
                    'original_error' => $result->getError(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Transition to the next step.
     */
    protected function transitionToNext(
        DynaflowInstance $instance,
        DynaflowStep $nextStep,
        mixed $user,
        ActionResult $previousResult,
        bool $chainExecution
    ): ActionResult {
        // Update instance
        $instance->update([
            'current_step_id' => $nextStep->id,
            'step_started_at' => now(),
        ]);

        // Check if next step is final
        if ($nextStep->is_final) {
            return $this->completeWorkflow($instance, $nextStep, $previousResult);
        }

        // Check if next step is also auto-executable
        if ($chainExecution && $nextStep->isAutoExecutable()) {
            // Dispatch job for next step to avoid deep recursion
            ExecuteAutoStepJob::dispatch($instance->fresh(), $nextStep, $user);

            return ActionResult::success([
                'chained_to'  => $nextStep->key,
                'dispatched'  => true,
            ]);
        }

        // Next step is stateful - stop here
        return ActionResult::success([
            'next_step' => $nextStep->key,
            'awaiting'  => 'human_decision',
        ]);
    }

    /**
     * Complete the workflow when reaching a final step.
     */
    protected function completeWorkflow(
        DynaflowInstance $instance,
        DynaflowStep $finalStep,
        ActionResult $result
    ): ActionResult {
        $status = $finalStep->workflow_status ?? DynaflowStatus::COMPLETED->value;

        $instance->update([
            'status'       => $status,
            'completed_at' => now(),
        ]);

        // Mark data as applied
        $instance->dynaflowData?->update(['applied' => true]);

        // Create context for completion hooks
        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $finalStep,
            decision: 'completed',
            user: $instance->triggeredBy,
            sourceStep: null,
            execution: null,
            notes: 'Auto-completed via auto-step execution',
            data: $result->getData()
        );

        // Run completion hooks
        $this->hookManager->runCompleteHooks($ctx);

        event(new DynaflowCompleted($ctx));

        return ActionResult::success([
            'completed'   => true,
            'status'      => $status,
            'final_step'  => $finalStep->key,
        ]);
    }

    /**
     * Check if a step should be auto-executed.
     */
    public function shouldAutoExecute(DynaflowStep $step): bool
    {
        return $step->isAutoExecutable();
    }
}
