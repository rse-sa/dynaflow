<?php

namespace RSE\DynaFlow;

use Closure;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowHookManager
{
    protected array $beforeTransitionToHooks = [];

    protected array $afterTransitionToHooks = [];

    protected array $transitionHooks = [];

    protected array $completeHooks = [];

    protected array $cancelHooks = [];

    protected array $beforeTriggerHooks = [];

    protected array $afterTriggerHooks = [];

    protected ?Closure $authorizationResolver = null;

    protected ?Closure $exceptionResolver = null;

    protected ?Closure $assigneeResolver = null;

    protected array $workflowAuthorizers = [];

    public function beforeTransitionTo(string $stepIdentifier, Closure $callback): void
    {
        $this->beforeTransitionToHooks[$stepIdentifier][] = $callback;
    }

    public function afterTransitionTo(string $stepIdentifier, Closure $callback): void
    {
        $this->afterTransitionToHooks[$stepIdentifier][] = $callback;
    }

    public function onTransition(string $from, string $to, Closure $callback): void
    {
        $this->transitionHooks[$from . '::' . $to][] = $callback;
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
        $this->beforeTriggerHooks[$topic . '::' . $action][] = $callback;
    }

    public function afterTrigger(string $topic, string $action, Closure $callback): void
    {
        $this->afterTriggerHooks[$topic . '::' . $action][] = $callback;
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
    public function runBeforeTransitionToHooks(DynaflowContext $ctx): bool
    {
        $step = $ctx->targetStep;

        $hooks = array_merge(
            $this->beforeTransitionToHooks['*'] ?? [],
            $this->beforeTransitionToHooks[$step->id] ?? [],
            $this->beforeTransitionToHooks[$step->key] ?? [],
            $this->beforeTransitionToHooks[$step->dynaflow->action . ':' . $step->key] ?? [],
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
    public function runAfterTransitionToHooks(DynaflowContext $ctx): void
    {
        $step = $ctx->targetStep;

        $hooks = array_merge(
            $this->afterTransitionToHooks['*'] ?? [],
            $this->afterTransitionToHooks[$step->id] ?? [],
            $this->afterTransitionToHooks[$step->key] ?? [],
            $this->afterTransitionToHooks[$step->dynaflow->action . ':' . $step->key] ?? [],
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

        $fromLongKey = $from->dynaflow->action . ':' . $from->key;
        $toLongKey   = $to->dynaflow->action . ':' . $to->key;

        $keys = [
            '*::*',
            '*::' . $to->id,
            '*::' . $to->key,
            '*::' . $toLongKey,
            $from->id . '::*',
            $from->key . '::*',
            $fromLongKey . '::*',
            $from->id . '::' . $to->id,
            $from->key . '::' . $to->key,
            $fromLongKey . '::' . $to->key,
            $from->key . '::' . $toLongKey,
            $fromLongKey . '::' . $toLongKey,
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

    /**
     * Run afterTrigger hooks for a workflow.
     */
    public function runAfterTriggerHooks(Dynaflow $workflow, DynaflowInstance $instance, mixed $model, mixed $user): void
    {
        $topic  = $workflow->topic;
        $action = $workflow->action;
        $key    = $topic . '::' . $action;

        $hooks = array_merge(
            $this->afterTriggerHooks['*::*'] ?? [],
            $this->afterTriggerHooks["$topic::*"] ?? [],
            $this->afterTriggerHooks["*::$action"] ?? [],
            $this->afterTriggerHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($workflow, $instance, $model, $user);
        }
    }

    /**
     * Register per-workflow authorization resolver
     * Takes precedence over global authorizeStepUsing
     */
    public function authorizeWorkflowStepUsing(string $topic, string $action, Closure $callback): void
    {
        $key                               = "$topic::$action";
        $this->workflowAuthorizers[$key]   = $callback;
    }

    /**
     * Check if per-workflow authorizer exists
     */
    public function hasWorkflowAuthorizer(string $key): bool
    {
        return isset($this->workflowAuthorizers[$key]);
    }

    /**
     * Resolve workflow-specific authorization
     */
    public function resolveWorkflowAuthorization(string $key, DynaflowStep $step, mixed $user, DynaflowInstance $instance): ?bool
    {
        if (! isset($this->workflowAuthorizers[$key])) {
            return null;
        }

        return $this->workflowAuthorizers[$key]($step, $user, $instance);
    }

    /**
     * Check if workflow will be bypassed for the given user
     */
    public function willBypass(string $topic, string $action, mixed $user): bool
    {
        $workflow = Dynaflow::where('topic', $topic)
            ->where('action', $action)
            ->where('active', true)
            ->first();

        if (! $workflow) {
            return false; // No workflow = no bypass (will apply directly)
        }

        return app(DynaflowValidator::class)->shouldBypassDynaflow($workflow, $user);
    }
}
