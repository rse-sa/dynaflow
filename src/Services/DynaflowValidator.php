<?php

namespace RSE\DynaFlow\Services;

use Illuminate\Database\Eloquent\Model;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;

class DynaflowValidator
{
    public function __construct(
        protected DynaflowHookManager $hookManager
    ) {}

    public function shouldBypassDynaflow(Dynaflow $workflow, $user): bool
    {
        if ($this->hookManager->hasExceptionResolver()) {
            $customResult = $this->hookManager->resolveException($workflow, $user);

            if ($customResult !== null) {
                return $customResult;
            }
        }

        $exceptions = $workflow->exceptions()
            ->where('exceptionable_type', $user->getMorphClass())
            ->where('exceptionable_id', $user->getKey())
            ->get();

        foreach ($exceptions as $exception) {
            if ($exception->isActive()) {
                return true;
            }
        }

        return false;
    }

    public function getActiveDuplicateInstance(Dynaflow $workflow, Model $model): ?DynaflowInstance
    {
        return DynaflowInstance::where('dynaflow_id', $workflow->id)
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->where('status', 'pending')
            ->first();
    }

    public function canUserExecuteStep(DynaflowStep $step, $user, ?DynaflowInstance $instance = null): bool
    {
        $workflow    = $step->dynaflow;
        $workflowKey = $workflow->topic . '::' . $workflow->action;

        // 1. Check per-workflow authorizer (highest priority)
        if ($instance && $this->hookManager->hasWorkflowAuthorizer($workflowKey)) {
            $result = $this->hookManager->resolveWorkflowAuthorization($workflowKey, $step, $user, $instance);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. Check global custom resolver
        if ($this->hookManager->hasAuthorizationResolver()) {
            $customResult = $this->hookManager->resolveAuthorization($step, $user);

            if ($customResult !== null) {
                return $customResult;
            }
        }

        // 3. Check database assignees
        $assignees = $this->hookManager->hasAssigneeResolver()
            ? $this->hookManager->resolveAssignees($step, $user)
            : $step->assignees->pluck('assignable_id')->toArray();

        if (empty($assignees)) {
            return true;
        }

        return in_array($user->getKey(), $assignees);
    }
}
