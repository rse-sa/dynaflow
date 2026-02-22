<?php

namespace RSE\DynaFlow;

use Closure;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\ActionHandlerRegistry;
use RSE\DynaFlow\Services\CallbackInvoker;
use RSE\DynaFlow\Services\DynaflowHookBuilder;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowHookManager
{
    protected CallbackInvoker $invoker;

    protected DynaflowLogger $logger;

    public function __construct(?CallbackInvoker $invoker = null, ?DynaflowLogger $logger = null)
    {
        $this->invoker = $invoker ?? new CallbackInvoker;
        $this->logger  = $logger ?? new DynaflowLogger;
    }

    /**
     * Get a fluent hook builder interface.
     */
    public function builder(): DynaflowHookBuilder
    {
        return new DynaflowHookBuilder($this);
    }

    /**
     * Scope hooks to a specific workflow.
     *
     * @param  string  $topic  The topic (e.g., Post::class)
     * @param  string  $action  The action (e.g., 'create', 'update')
     * @return $this
     */
    public function forWorkflow(string $topic, string $action): DynaflowHookBuilder
    {
        return $this->builder()->forWorkflow($topic, $action);
    }

    protected array $beforeTransitionToHooks = [];

    protected array $afterTransitionToHooks = [];

    protected array $transitionHooks = [];

    protected array $completeHooks = [];

    protected array $cancelHooks = [];

    protected array $beforeTriggerHooks = [];

    protected array $afterTriggerHooks = [];

    protected array $stepActivatedHooks = [];

    protected array $authorizationResolvers = [];

    protected array $exceptionResolvers = [];

    protected array $assigneeResolvers = [];

    protected array $scripts = [];

    protected array $aiResolvers = [];

    protected array $workflowResolvers = [];

    /**
     * Clear all registered hooks and resolvers. Useful for test isolation.
     */
    public function reset(): void
    {
        $this->beforeTransitionToHooks = [];
        $this->afterTransitionToHooks  = [];
        $this->transitionHooks         = [];
        $this->completeHooks           = [];
        $this->cancelHooks             = [];
        $this->beforeTriggerHooks      = [];
        $this->afterTriggerHooks       = [];
        $this->stepActivatedHooks      = [];
        $this->authorizationResolvers  = [];
        $this->exceptionResolvers      = [];
        $this->assigneeResolvers       = [];
        $this->scripts                 = [];
        $this->aiResolvers             = [];
        $this->workflowResolvers       = [];
    }

    // -------------------------------------------------------------------------
    // Internal push* methods (@internal — called by builder leaf classes)
    // -------------------------------------------------------------------------

    /**
     * @internal Use the builder API: Dynaflow::builder()->beforeTransitionTo(...)
     */
    public function pushBeforeTransitionToHook(string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->beforeTransitionToHooks[$identifier][] = $callback;
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::builder()->afterTransitionTo(...)
     */
    public function pushAfterTransitionToHook(string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->afterTransitionToHooks[$identifier][] = $callback;
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::builder()->transition()->from(...)->to(...)
     */
    public function pushTransitionHook(string|array $from, string|array $to, Closure $callback): void
    {
        $froms = is_array($from) ? $from : [$from];
        $tos   = is_array($to) ? $to : [$to];

        foreach ($froms as $fromStep) {
            foreach ($tos as $toStep) {
                $this->transitionHooks[$fromStep . '::' . $toStep][] = $callback;
            }
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->whenCompleted()
     */
    public function pushCompleteHook(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->completeHooks["$t::$a"][] = $callback;
            }
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->whenCancelled()
     */
    public function pushCancelHook(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->cancelHooks["$t::$a"][] = $callback;
            }
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->beforeTriggering()
     */
    public function pushBeforeTriggerHook(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->beforeTriggerHooks["$t::$a"][] = $callback;
            }
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->afterTriggering()
     */
    public function pushAfterTriggerHook(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->afterTriggerHooks["$t::$a"][] = $callback;
            }
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->whenStepActivated(...)
     */
    public function pushStepActivatedHook(string $topic, string $action, string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->stepActivatedHooks["$topic::$action::$identifier"][] = $callback;
        }
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->authorizeStepUsing()
     */
    public function pushAuthorizationResolver(string $topic, string $action, Closure $callback): void
    {
        $this->authorizationResolvers["$topic::$action"] = $callback;
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->resolveExceptionUsing()
     */
    public function pushExceptionResolver(string $topic, string $action, Closure $callback): void
    {
        $this->exceptionResolvers["$topic::$action"] = $callback;
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->resolveAssigneesUsing()
     */
    public function pushAssigneeResolver(string $topic, string $action, Closure $callback): void
    {
        $this->assigneeResolvers["$topic::$action"] = $callback;
    }

    /**
     * @internal Use the builder API: Dynaflow::forWorkflow(...)->resolveWorkflowUsing()
     */
    public function pushWorkflowResolver(string $topic, string $action, Closure $callback): void
    {
        $this->workflowResolvers["$topic::$action"] = $callback;
    }

    // -------------------------------------------------------------------------
    // Deprecated public registration methods — use builder API instead
    // -------------------------------------------------------------------------

    /**
     * @deprecated Use Dynaflow::builder()->beforeTransitionTo(...)->execute(...)
     */
    public function beforeTransitionTo(string|array $stepIdentifier, Closure $callback): void
    {
        $this->pushBeforeTransitionToHook($stepIdentifier, $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->afterTransitionTo(...)->execute(...)
     */
    public function afterTransitionTo(string|array $stepIdentifier, Closure $callback): void
    {
        $this->pushAfterTransitionToHook($stepIdentifier, $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->transition()->from(...)->to(...)->execute(...)
     */
    public function onTransition(string|array $from, string|array $to, Closure $callback): void
    {
        $this->pushTransitionHook($from, $to, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->whenCompleted()->execute(...)
     */
    public function onComplete(string|array $topic, string|array $action, Closure $callback): void
    {
        $this->pushCompleteHook($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->whenCancelled()->execute(...)
     */
    public function onCancel(string|array $topic, string|array $action, Closure $callback): void
    {
        $this->pushCancelHook($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->beforeTriggering()->execute(...)
     */
    public function beforeTrigger(string|array $topic, string|array $action, Closure $callback): void
    {
        $this->pushBeforeTriggerHook($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->afterTriggering()->execute(...)
     */
    public function afterTrigger(string|array $topic, string|array $action, Closure $callback): void
    {
        $this->pushAfterTriggerHook($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->whenStepActivated($stepIdentifier)->execute(...)
     */
    public function onStepActivatedFor(string $topic, string $action, string|array $stepIdentifier, Closure $callback): void
    {
        $this->pushStepActivatedHook($topic, $action, $stepIdentifier, $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->whenStepActivated($stepIdentifier)->execute(...)
     */
    public function onStepActivated(string|array $stepIdentifier, Closure $callback): void
    {
        $this->pushStepActivatedHook('*', '*', $stepIdentifier, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->authorizeStepUsing()->execute(...)
     */
    public function authorizeStepFor(string $topic, string $action, Closure $callback): void
    {
        $this->pushAuthorizationResolver($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->resolveExceptionUsing()->execute(...)
     */
    public function exceptionFor(string $topic, string $action, Closure $callback): void
    {
        $this->pushExceptionResolver($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->resolveAssigneesUsing()->execute(...)
     */
    public function resolveAssigneesFor(string $topic, string $action, Closure $callback): void
    {
        $this->pushAssigneeResolver($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->authorizeStepUsing()->execute(...)
     */
    public function authorizeStepUsing(Closure $callback): void
    {
        $this->pushAuthorizationResolver('*', '*', $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->resolveExceptionUsing()->execute(...)
     */
    public function exceptionUsing(Closure $callback): void
    {
        $this->pushExceptionResolver('*', '*', $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->resolveAssigneesUsing()->execute(...)
     */
    public function resolveAssigneesUsing(Closure $callback): void
    {
        $this->pushAssigneeResolver('*', '*', $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->resolveWorkflowUsing()->execute(...)
     */
    public function registerWorkflowResolver(string $topic, string $action, Closure $callback): void
    {
        $this->pushWorkflowResolver($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->resolveWorkflowUsing()->execute(...)
     */
    public function resolveWorkflowFor(string $topic, string $action, Closure $callback): void
    {
        $this->pushWorkflowResolver($topic, $action, $callback);
    }

    /**
     * @deprecated Use Dynaflow::builder()->resolveWorkflowUsing()->execute(...)
     */
    public function resolveWorkflowUsing(Closure $callback): void
    {
        $this->pushWorkflowResolver('*', '*', $callback);
    }

    /**
     * Attempt to resolve the Dynaflow via registered resolver callbacks.
     * Returns null if no resolver matched or all returned null.
     */
    public function resolveWorkflow(string $topic, string $action, mixed $model, array $data, mixed $user): ?Dynaflow
    {
        $available = [
            'topic'  => $topic,
            'action' => $action,
            'model'  => $model,
            'data'   => $data,
            'user'   => $user,
        ];

        return $this->resolveFromResolvers($this->workflowResolvers, $topic, $action, $available);
    }

    /**
     * Build available parameters from DynaflowContext for callback invocation.
     *
     * @param  DynaflowContext  $ctx  The workflow context
     * @return array Available parameters
     */
    private function buildContextParameters(DynaflowContext $ctx): array
    {
        return [
            'ctx'         => $ctx,
            'context'     => $ctx,
            'instance'    => $ctx->instance,
            'sourceStep'  => $ctx->sourceStep,
            'targetStep'  => $ctx->targetStep,
            'step'        => $ctx->targetStep,
            'decision'    => $ctx->decision,
            'user'        => $ctx->user,
            'execution'   => $ctx->execution,
            'notes'       => $ctx->notes,
            'model'       => $ctx->model(),
            'data'        => $ctx->pendingData(),
            'workflow'    => $ctx->instance->dynaflow,
        ];
    }

    /**
     * Get resolver keys in priority order (most specific to least specific).
     *
     * @param  string  $topic  The workflow topic
     * @param  string  $action  The workflow action
     * @return array<string> Keys in priority order
     */
    private function getResolverKeys(string $topic, string $action): array
    {
        return [
            "$topic::$action",  // Exact match
            "$topic::*",        // Topic wildcard
            "*::$action",       // Action wildcard
            '*::*',             // Global wildcard
        ];
    }

    /**
     * Resolve from resolvers array with priority.
     *
     * @param  array  $resolvers  The resolvers array
     * @param  string  $topic  The workflow topic
     * @param  string  $action  The workflow action
     * @param  array  $available  Available parameters for resolver
     * @return mixed The first non-null result or null
     */
    private function resolveFromResolvers(array $resolvers, string $topic, string $action, array $available): mixed
    {
        foreach ($this->getResolverKeys($topic, $action) as $key) {
            if (isset($resolvers[$key])) {
                $result = $this->invoker->invoke($resolvers[$key], $available);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
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

        $available = $this->buildContextParameters($ctx);

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: beforeTransitionTo', [
                'step'  => $step->key,
                'count' => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $result = $this->invoker->invoke($hook, $available);
            if ($result === false) {
                $this->logger->debug('Hook result: beforeTransitionTo — blocked', ['step' => $step->key]);

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

        $available = $this->buildContextParameters($ctx);

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: afterTransitionTo', [
                'step'  => $step->key,
                'count' => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
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

        $available = $this->buildContextParameters($ctx);

        foreach ($keys as $key) {
            if (! isset($this->transitionHooks[$key])) {
                continue;
            }

            $this->logger->debug('Hook firing: onTransition', [
                'from'  => $from->key,
                'to'    => $to->key,
                'count' => count($this->transitionHooks[$key]),
            ]);

            foreach ($this->transitionHooks[$key] as $hook) {
                $result = $this->invoker->invoke($hook, $available);
                if ($result === false) {
                    $this->logger->debug('Hook result: onTransition — blocked', [
                        'from' => $from->key,
                        'to'   => $to->key,
                    ]);

                    return false;
                }
            }
        }

        return true;
    }

    public function resolveAuthorization(DynaflowStep $step, $user, ?DynaflowInstance $instance = null): ?bool
    {
        $topic  = $step->dynaflow->topic;
        $action = $step->dynaflow->action;

        $available = [
            'step'     => $step,
            'user'     => $user,
            'instance' => $instance,
            'workflow' => $step->dynaflow,
            'model'    => $instance?->model,
        ];

        return $this->resolveFromResolvers(
            $this->authorizationResolvers,
            $topic,
            $action,
            $available
        );
    }

    public function resolveException(Dynaflow $workflow, $user): ?bool
    {
        $topic  = $workflow->topic;
        $action = $workflow->action;

        $available = [
            'workflow' => $workflow,
            'user'     => $user,
        ];

        return $this->resolveFromResolvers(
            $this->exceptionResolvers,
            $topic,
            $action,
            $available
        );
    }

    public function resolveAssignees(DynaflowStep $step, $user, ?DynaflowInstance $instance = null): array
    {
        $step->loadMissing(['dynaflow', 'allowedTransitions']);

        $topic  = $step->dynaflow->topic;
        $action = $step->dynaflow->action;

        $available = [
            'step'     => $step,
            'user'     => $user,
            'instance' => $instance,
            'workflow' => $step->dynaflow,
            'model'    => $instance?->model,
        ];

        $result = $this->resolveFromResolvers(
            $this->assigneeResolvers,
            $topic,
            $action,
            $available
        );

        return $result ?? [];
    }

    public function hasAuthorizationResolver(): bool
    {
        return ! empty($this->authorizationResolvers);
    }

    public function hasExceptionResolver(): bool
    {
        return ! empty($this->exceptionResolvers);
    }

    public function hasAssigneeResolver(): bool
    {
        return ! empty($this->assigneeResolvers);
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

        $available = $this->buildContextParameters($ctx);

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: onComplete', [
                'topic'  => $topic,
                'action' => $action,
                'count'  => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
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

        $available = $this->buildContextParameters($ctx);

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: onCancel', [
                'topic'  => $topic,
                'action' => $action,
                'count'  => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
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
     * Run step activated hooks.
     *
     * Called when a step becomes the current step of an instance.
     *
     * @param  DynaflowInstance  $instance  The workflow instance
     * @param  DynaflowStep  $step  The activated step
     * @param  mixed  $user  The user who triggered activation
     */
    public function runStepActivatedHooks(DynaflowInstance $instance, DynaflowStep $step, mixed $user): void
    {
        $workflow = $instance->dynaflow;
        $topic    = $workflow->topic;
        $action   = $workflow->action;
        $longKey  = $action . ':' . $step->key;

        // Build keys with scoping priority: topic::action::step > topic::*::step > *::action::step > *::*::step
        $keys = [
            "$topic::$action::$step->id",
            "$topic::$action::$step->key",
            "$topic::$action::$longKey",
            "$topic::$action::*",
            "$topic::*::$step->id",
            "$topic::*::$step->key",
            "$topic::*::$longKey",
            "$topic::*::*",
            "*::$action::$step->id",
            "*::$action::$step->key",
            "*::$action::$longKey",
            "*::$action::*",
            "*::*::$step->id",
            "*::*::$step->key",
            "*::*::$longKey",
            '*::*::*',
        ];

        $hooks = [];
        foreach ($keys as $key) {
            if (isset($this->stepActivatedHooks[$key])) {
                $hooks = array_merge($hooks, $this->stepActivatedHooks[$key]);
            }
        }

        $available = [
            'instance' => $instance,
            'step'     => $step,
            'workflow' => $workflow,
            'user'     => $user,
            'model'    => $instance->model,
        ];

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: onStepActivated', [
                'step'   => $step->key,
                'topic'  => $topic,
                'action' => $action,
                'count'  => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
        }
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

        $available = [
            'workflow' => $workflow,
            'model'    => $model,
            'data'     => $data,
            'user'     => $user,
        ];

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: beforeTrigger', [
                'topic'  => $topic,
                'action' => $action,
                'count'  => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $result = $this->invoker->invoke($hook, $available);
            if ($result === false) {
                $this->logger->debug('Hook result: beforeTrigger — skipped', [
                    'topic'  => $topic,
                    'action' => $action,
                ]);

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

        $available = [
            'workflow' => $workflow,
            'instance' => $instance,
            'model'    => $model,
            'user'     => $user,
        ];

        if (! empty($hooks)) {
            $this->logger->debug('Hook firing: afterTrigger', [
                'topic'      => $topic,
                'action'     => $action,
                'instance'   => $instance->id,
                'count'      => count($hooks),
            ]);
        }

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
        }
    }

    /**
     * @deprecated Use Dynaflow::forWorkflow($topic, $action)->authorizeStepUsing()->execute(...)
     */
    public function authorizeWorkflowStepUsing(string $topic, string $action, Closure $callback): void
    {
        $this->pushAuthorizationResolver($topic, $action, $callback);
    }

    /**
     * Check if per-workflow authorizer exists
     */
    public function hasWorkflowAuthorizer(string $key): bool
    {
        return isset($this->authorizationResolvers[$key]);
    }

    /**
     * Resolve workflow-specific authorization
     */
    public function resolveWorkflowAuthorization(string $key, DynaflowStep $step, mixed $user, DynaflowInstance $instance): ?bool
    {
        if (! isset($this->authorizationResolvers[$key])) {
            return null;
        }

        $available = [
            'step'     => $step,
            'user'     => $user,
            'instance' => $instance,
            'workflow' => $step->dynaflow,
            'model'    => $instance->model,
        ];

        return $this->invoker->invoke($this->authorizationResolvers[$key], $available);
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

    /**
     * Register an action handler.
     *
     * @param  string  $key  Handler identifier (e.g., 'email', 'http', 'script')
     * @param  ActionHandler|Closure|class-string<ActionHandler>  $handler  Handler
     */
    public function registerAction(string $key, ActionHandler|Closure|string $handler): void
    {
        app(ActionHandlerRegistry::class)->register($key, $handler);
    }

    /**
     * Get an action handler.
     *
     * @param  string  $key  Handler identifier
     */
    public function getActionHandler(string $key): ?ActionHandler
    {
        return app(ActionHandlerRegistry::class)->get($key);
    }

    /**
     * Register a script for use in script and decision handlers.
     *
     * Scripts are PHP closures that can be selected by admins in the
     * visual designer but are defined by developers for security.
     *
     * @param  string  $key  Script identifier
     * @param  Closure  $script  The script closure: fn(DynaflowContext $ctx, array $params): mixed
     */
    public function registerScript(string $key, Closure $script): void
    {
        $this->scripts[$key] = $script;
    }

    /**
     * Get a registered script.
     *
     * @param  string  $key  Script identifier
     */
    public function getScript(string $key): ?Closure
    {
        return $this->scripts[$key] ?? null;
    }

    /**
     * Get all registered script keys.
     *
     * @return array<string>
     */
    public function getScriptKeys(): array
    {
        return array_keys($this->scripts);
    }

    /**
     * Register an AI decision resolver.
     *
     * AI resolvers handle routing decisions for decision nodes in AI mode.
     *
     * @param  string  $provider  Provider identifier (e.g., 'openai', 'anthropic')
     * @param  Closure|class-string  $resolver  Resolver: fn(string $prompt, array $allowedRoutes, array $options): string
     */
    public function registerAIResolver(string $provider, Closure|string $resolver): void
    {
        $this->aiResolvers[$provider] = $resolver;
    }

    /**
     * Get an AI decision resolver.
     *
     * @param  string  $provider  Provider identifier
     * @return \Closure|null The resolver or null if not found
     */
    public function getAIResolver(string $provider): ?Closure
    {
        $resolver = $this->aiResolvers[$provider] ?? null;

        if (is_string($resolver)) {
            return app($resolver);
        }

        return $resolver;
    }

    /**
     * Check if an AI resolver is registered.
     *
     * @param  string  $provider  Provider identifier
     */
    public function hasAIResolver(string $provider): bool
    {
        return isset($this->aiResolvers[$provider]);
    }
}
