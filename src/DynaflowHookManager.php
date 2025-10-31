<?php

namespace RSE\DynaFlow;

use Closure;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;

class DynaflowHookManager
{
    protected array $beforeStepHooks = [];

    protected array $afterStepHooks = [];

    protected array $transitionHooks = [];

    protected array $completeHooks = [];

    protected array $rejectHooks = [];

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
        $key                           = "{$from}::{$to}";
        $this->transitionHooks[$key][] = $callback;
    }

    /**
     * Register a hook to execute when a workflow completes successfully.
     *
     * The callback receives: (DynaflowInstance $instance, $user)
     * The callback should perform the final action (create/update/delete/approve/etc)
     *
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onComplete(string $topic, string $action, Closure $callback): void
    {
        $key = "{$topic}::{$action}";
        $this->completeHooks[$key][] = $callback;
    }

    /**
     * Register a hook to execute when a workflow is rejected or cancelled.
     *
     * The callback receives: (DynaflowInstance $instance, $user, $decision)
     * Use this to clean up, notify users, or perform rollback actions
     *
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onReject(string $topic, string $action, Closure $callback): void
    {
        $key = "{$topic}::{$action}";
        $this->rejectHooks[$key][] = $callback;
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

    public function runBeforeStepHooks(DynaflowStep $step, DynaflowInstance $instance, $user): bool
    {
        $hooks = array_merge(
            $this->beforeStepHooks['*'] ?? [],
            $this->beforeStepHooks[$step->id] ?? [],
            $this->beforeStepHooks[$step->name] ?? []
        );

        foreach ($hooks as $hook) {
            $result = $hook($step, $instance, $user);
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    public function runAfterStepHooks(DynaflowStepExecution $execution): void
    {
        $step = $execution->step;

        $hooks = array_merge(
            $this->afterStepHooks['*'] ?? [],
            $this->afterStepHooks[$step->id] ?? [],
            $this->afterStepHooks[$step->name] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($execution);
        }
    }

    public function runTransitionHooks(DynaflowStep $from, DynaflowStep $to, DynaflowInstance $instance, $user): bool
    {
        $keys = [
            '*::*',
            "*::{$to->id}",
            "*::{$to->name}",
            "{$from->id}::*",
            "{$from->name}::*",
            "{$from->id}::{$to->id}",
            "{$from->name}::{$to->name}",
        ];

        foreach ($keys as $key) {
            if (! isset($this->transitionHooks[$key])) {
                continue;
            }

            foreach ($this->transitionHooks[$key] as $hook) {
                $result = $hook($from, $to, $instance, $user);
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

    public function runCompleteHooks(DynaflowInstance $instance, $user): void
    {
        $topic = $instance->dynaflow->topic;
        $action = $instance->dynaflow->action;
        $key = "{$topic}::{$action}";

        $hooks = array_merge(
            $this->completeHooks['*::*'] ?? [],
            $this->completeHooks["{$topic}::*"] ?? [],
            $this->completeHooks["*::{$action}"] ?? [],
            $this->completeHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($instance, $user);
        }
    }

    public function runRejectHooks(DynaflowInstance $instance, $user, string $decision): void
    {
        $topic = $instance->dynaflow->topic;
        $action = $instance->dynaflow->action;
        $key = "{$topic}::{$action}";

        $hooks = array_merge(
            $this->rejectHooks['*::*'] ?? [],
            $this->rejectHooks["{$topic}::*"] ?? [],
            $this->rejectHooks["*::{$action}"] ?? [],
            $this->rejectHooks[$key] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($instance, $user, $decision);
        }
    }

    public function hasCompleteHook(string $topic, string $action): bool
    {
        $key = "{$topic}::{$action}";

        return ! empty($this->completeHooks['*::*'])
            || ! empty($this->completeHooks["{$topic}::*"])
            || ! empty($this->completeHooks["*::{$action}"])
            || ! empty($this->completeHooks[$key]);
    }
}
