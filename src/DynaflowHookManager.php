<?php

namespace RSE\DynaFlow;

use Closure;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowHookManager
{
    protected array $beforeStepHooks = [];

    protected array $afterStepHooks = [];

    protected array $transitionHooks = [];

    protected array $completeHooks = [];

    protected array $cancelHooks = [];

    protected array $beforeTriggerHooks = [];

    protected ?Closure $authorizationResolver = null;

    protected ?Closure $exceptionResolver = null;

    protected ?Closure $assigneeResolver = null;

    public function beforeStep(string $stepIdentifier, Closure $callback): void
    {
        $this->beforeStepHooks[$stepIdentifier][] = $callback;
    }

    public function afterStep(string $stepIdentifier, Closure $callback): void
    {
        $this->afterStepHooks[$stepIdentifier][] = $callback;
    }

    public function onTransition(string $from, string $to, Closure $callback): void
    {
        $key                           = "$from::$to";
        $this->transitionHooks[$key][] = $callback;
    }

    /**
     * Register a hook to execute when a workflow completes successfully.
     *
     * The callback receives: (DynaflowContext $context)
     * The callback should perform the final action (create/update/delete/approve/etc)
     *
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onComplete(string $topic, string $action, Closure $callback): void
    {
        $key                         = "$topic::$action";
        $this->completeHooks[$key][] = $callback;
    }

    /**
     * Register a hook to execute when a workflow is cancelled or rejected.
     *
     * The callback receives: (DynaflowContext $context)
     * Use this to clean up, notify users, or perform rollback actions
     *
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onCancel(string $topic, string $action, Closure $callback): void
    {
        $key                       = "$topic::$action";
        $this->cancelHooks[$key][] = $callback;
    }

    /**
     * Register a hook to execute before triggering a workflow.
     *
     * The callback receives: (Dynaflow $workflow, ?Model $model, array $data, $user)
     * Return FALSE to skip workflow and apply changes directly.
     * Return TRUE or NULL to continue with workflow.
     *
     * Use this to:
     * - Check specific fields that changed
     * - Apply custom business logic for skipping workflows
     * - Conditionally bypass workflows based on data
     *
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function beforeTrigger(string $topic, string $action, Closure $callback): void
    {
        $key                              = "$topic::$action";
        $this->beforeTriggerHooks[$key][] = $callback;
    }

    public function authorizeStepUsing(Closure $callback): void
    {
        $this->authorizationResolver = $callback;
    }

    public function exceptionUsing(Closure $callback): void
    {
        $this->exceptionResolver = $callback;
    }

    public function resolveAssigneesUsing(Closure $callback): void
    {
        $this->assigneeResolver = $callback;
    }

    /**
     * Run before step hooks
     *
     * @param  DynaflowContext  $ctx  The workflow context
     * @return bool Returns FALSE if any hook blocks execution
     */
    public function runBeforeStepHooks(DynaflowContext $ctx): bool
    {
        $step = $ctx->targetStep;

        $hooks = array_merge(
            $this->beforeStepHooks['*'] ?? [],
            $this->beforeStepHooks[$step->id] ?? [],
            $this->beforeStepHooks[$step->key] ?? [],
        );

        foreach ($hooks as $hook) {
            $result = $hook($ctx);
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run after step hooks
     *
     * @param  DynaflowContext  $ctx  The workflow context
     */
    public function runAfterStepHooks(DynaflowContext $ctx): void
    {
        $step = $ctx->targetStep;

        $hooks = array_merge(
            $this->afterStepHooks['*'] ?? [],
            $this->afterStepHooks[$step->id] ?? [],
            $this->afterStepHooks[$step->key] ?? [],
        );

        foreach ($hooks as $hook) {
            $hook($ctx);
        }
    }

    /**
     * Run transition hooks
     *
     * @param  DynaflowContext  $ctx  The workflow context
     * @return bool Returns FALSE if any hook blocks the transition
     */
    public function runTransitionHooks(DynaflowContext $ctx): bool
    {
        $from = $ctx->sourceStep;
        $to   = $ctx->targetStep;

        $keys = [
            '*::*',
            '*::' . $to->id,
            '*::' . $to->key,
            $from->id . '::*',
            $from->key . '::*',
            $from->id . '::' . $to->id,
            $from->key . '::' . $to->key,
        ];

        foreach ($keys as $key) {
            if (! isset($this->transitionHooks[$key])) {
                continue;
            }

            foreach ($this->transitionHooks[$key] as $hook) {
                $result = $hook($ctx);
                if ($result === false) {
                    return false;
                }
            }
        }

        return true;
    }

    public function resolveAuthorization(DynaflowStep $step, $user): ?bool
    {
        if ($this->authorizationResolver === null) {
            return null;
        }

        return ($this->authorizationResolver)($step, $user);
    }

    public function resolveException(Dynaflow $workflow, $user): ?bool
    {
        if ($this->exceptionResolver === null) {
            return null;
        }

        return ($this->exceptionResolver)($workflow, $user);
    }

    public function resolveAssignees(DynaflowStep $step, $user): array
    {
        if ($this->assigneeResolver === null) {
            return [];
        }

        return ($this->assigneeResolver)($step, $user);
    }

    public function hasAuthorizationResolver(): bool
    {
        return $this->authorizationResolver !== null;
    }

    public function hasExceptionResolver(): bool
    {
        return $this->exceptionResolver !== null;
    }

    public function hasAssigneeResolver(): bool
    {
        return $this->assigneeResolver !== null;
    }

    /**
     * Run completion hooks
     *
     * @param  DynaflowContext  $ctx  The workflow context
     */
    public function runCompleteHooks(DynaflowContext $ctx): void
    {
        $topic  = $ctx->topic();
        $action = $ctx->action();
        $key    = "$topic::$action";

        $hooks = array_merge(
            $this->completeHooks['*::*'] ?? [],
            $this->completeHooks["$topic::*"] ?? [],
            $this->completeHooks["*::$action"] ?? [],
            $this->completeHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($ctx);
        }
    }

    /**
     * Run cancellation hooks
     *
     * @param  DynaflowContext  $ctx  The workflow context
     */
    public function runCancelHooks(DynaflowContext $ctx): void
    {
        $topic  = $ctx->topic();
        $action = $ctx->action();
        $key    = "$topic::$action";

        $hooks = array_merge(
            $this->cancelHooks['*::*'] ?? [],
            $this->cancelHooks["$topic::*"] ?? [],
            $this->cancelHooks["*::$action"] ?? [],
            $this->cancelHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($ctx);
        }
    }

    public function hasCompleteHook(string $topic, string $action): bool
    {
        $key = "$topic::$action";

        return ! empty($this->completeHooks['*::*'])
            || ! empty($this->completeHooks["$topic::*"])
            || ! empty($this->completeHooks["*::$action"])
            || ! empty($this->completeHooks[$key]);
    }

    /**
     * Run beforeTrigger hooks for a workflow.
     *
     * @return bool Returns FALSE if any hook returns FALSE (skip workflow), TRUE otherwise
     */
    public function runBeforeTriggerHooks(Dynaflow $workflow, mixed $model, array $data, mixed $user): bool
    {
        $topic  = $workflow->topic;
        $action = $workflow->action;
        $key    = "$topic::$action";

        $hooks = array_merge(
            $this->beforeTriggerHooks['*::*'] ?? [],
            $this->beforeTriggerHooks["$topic::*"] ?? [],
            $this->beforeTriggerHooks["*::$action"] ?? [],
            $this->beforeTriggerHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $result = $hook($workflow, $model, $data, $user);
            if ($result === false) {
                return false; // Skip workflow
            }
        }

        return true; // Continue with workflow
    }
}
