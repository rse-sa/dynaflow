<?php

namespace RSE\DynaFlow\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Enums\DynaflowStepDecision;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\DynaflowStepExecuted;
use RSE\DynaFlow\Events\DynaflowTriggered;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowData;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;

class DynaflowEngine
{
    public function __construct(
        protected DynaflowValidator $validator,
        protected DynaflowHookManager $hookManager
    ) {}

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

        return DB::transaction(function () use ($workflow, $model, $data, $user) {
            // Check for duplicates only if model exists
            if ($model && $model->exists) {
                $duplicateInstance = $this->validator->getActiveDuplicateInstance($workflow, $model);

                if ($duplicateInstance) {
                    $duplicateInstance->update(['status' => 'cancelled']);
                }
            }

            $instance = DynaflowInstance::create([
                'dynaflow_id'       => $workflow->id,
                'model_type'        => $model?->getMorphClass(),
                'model_id'          => $model?->getKey(),
                'status'            => 'pending',
                'triggered_by_type' => $user->getMorphClass(),
                'triggered_by_id'   => $user->getKey(),
                'current_step_id'   => $workflow->steps->first()?->id,
            ]);

            DynaflowData::create([
                'dynaflow_instance_id' => $instance->id,
                'data'                 => $data,
                'applied'              => false,
            ]);

            event(new DynaflowTriggered($instance));

            return $instance;
        });
    }

    /**
     * @throws \Throwable
     */
    public function executeStep(
        DynaflowInstance $instance,
        DynaflowStep $targetStep,
        string $decision,
        $user,
        ?string $note = null
    ): bool {
        if (! $instance->isPending()) {
            throw new \Exception('Dynaflow instance is not pending');
        }

        if (! $this->validator->canUserExecuteStep($instance->currentStep, $user)) {
            throw new \Exception('User not authorized to execute this step');
        }

        if (! $instance->currentStep->canTransitionTo($targetStep)) {
            throw new \Exception('Invalid step transition');
        }

        if (! $this->hookManager->runBeforeStepHooks($instance->currentStep, $instance, $user)) {
            throw new \Exception('Step execution blocked by hook');
        }

        if (! $this->hookManager->runTransitionHooks($instance->currentStep, $targetStep, $instance, $user)) {
            throw new \Exception('Transition blocked by hook');
        }

        return DB::transaction(function () use ($instance, $targetStep, $decision, $user, $note) {
            $lastExecution = $instance->executions()->latest('executed_at')->first();
            $durationHours = $lastExecution
                ? now()->diffInHours($lastExecution->executed_at)
                : now()->diffInHours($instance->created_at);

            $execution = DynaflowStepExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'dynaflow_step_id'     => $instance->current_step_id,
                'executed_by_type'     => $user->getMorphClass(),
                'executed_by_id'       => $user->getKey(),
                'decision'             => $decision,
                'note'                 => $note,
                'duration_hours'       => $durationHours,
                'executed_at'          => now(),
            ]);

            if (in_array($decision, [DynaflowStepDecision::REJECT->value, DynaflowStepDecision::CANCEL->value])) {
                $instance->update(['status' => 'cancelled']);

                // Run rejection hooks
                $this->hookManager->runRejectHooks($instance, $user, $decision);

                $this->hookManager->runAfterStepHooks($execution);
                event(new DynaflowStepExecuted($execution));

                return true;
            }

            $instance->update(['current_step_id' => $targetStep->id]);

            if ($targetStep->is_final && $decision === DynaflowStepDecision::APPROVE->value) {
                $this->completeDynaflow($instance);
            }

            $this->hookManager->runAfterStepHooks($execution);
            event(new DynaflowStepExecuted($execution));

            return true;
        });
    }

    protected function completeDynaflow(DynaflowInstance $instance): void
    {
        $workflowData = $instance->dynaflowData;

        // Get the user who approved the final step
        $finalExecution = $instance->executions()->latest('executed_at')->first();
        $user           = $finalExecution?->executedBy ?? $instance->triggeredBy;

        // Run completion hooks - these will handle the actual action
        $this->hookManager->runCompleteHooks($instance, $user);

        $workflowData->update(['applied' => true]);
        $instance->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        event(new DynaflowCompleted($instance));
    }

    /**
     * Apply changes directly without workflow (no workflow configured or user has exception).
     * Executes completion hooks to perform the actual action.
     *
     * @param  mixed  $user
     * @return mixed The result from the completion hook (usually the model)
     */
    protected function applyDirectly(string $topic, string $action, ?Model $model, array $data, $user): mixed
    {
        // Create a temporary instance for the hook to access data
        $tempInstance = new DynaflowInstance([
            'dynaflow_id' => null,
            'model_type'  => $model ? $model->getMorphClass() : null,
            'model_id'    => $model?->id,
            'status'      => 'completed',
        ]);

        // Attach a temporary dynaflowData relation
        $tempData = new DynaflowData(['data' => $data]);
        $tempInstance->setRelation('dynaflowData', $tempData);
        $tempInstance->setRelation('model', $model);

        // Store topic and action temporarily
        $tempWorkflow = new Dynaflow(['topic' => $topic, 'action' => $action]);
        $tempInstance->setRelation('dynaflow', $tempWorkflow);

        // Run completion hooks - they will perform the actual action
        $this->hookManager->runCompleteHooks($tempInstance, $user);

        // Return the model (or result from hook)
        return $tempInstance->model ?? $model;
    }
}
