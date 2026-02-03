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

    public function __construct(?CallbackInvoker $invoker = null)
    {
        $this->invoker = $invoker ?? new CallbackInvoker;
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

    public function beforeTransitionTo(string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->beforeTransitionToHooks[$identifier][] = $callback;
        }
    }

    public function afterTransitionTo(string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->afterTransitionToHooks[$identifier][] = $callback;
        }
    }

    public function onTransition(string|array $from, string|array $to, Closure $callback): void
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
     * Register a hook to execute when a workflow completes successfully.
     *
     * The callback receives: (DynaflowContext $context)
     * The callback should perform the final action (create/update/delete/approve/etc)
     *
     * @param  string|array  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string|array  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onComplete(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $key                         = "$t::$a";
                $this->completeHooks[$key][] = $callback;
            }
        }
    }

    /**
     * Register a hook to execute when a workflow is cancelled or rejected.
     *
     * The callback receives: (DynaflowContext $context)
     * Use this to clean up, notify users, or perform rollback actions
     *
     * @param  string|array  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string|array  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function onCancel(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $key                       = "$t::$a";
                $this->cancelHooks[$key][] = $callback;
            }
        }
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
     * @param  string|array  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string|array  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
     * @param  Closure  $callback  The callback to execute
     */
    public function beforeTrigger(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->beforeTriggerHooks[$t . '::' . $a][] = $callback;
            }
        }
    }

    public function afterTrigger(string|array $topic, string|array $action, Closure $callback): void
    {
        $topics  = is_array($topic) ? $topic : [$topic];
        $actions = is_array($action) ? $action : [$action];

        foreach ($topics as $t) {
            foreach ($actions as $a) {
                $this->afterTriggerHooks[$t . '::' . $a][] = $callback;
            }
        }
    }

    /**
     * Register a scoped hook for when a step becomes active in a specific workflow.
     *
     * @param  string  $topic  The topic (e.g., Post::class) or '*'
     * @param  string  $action  The action (e.g., 'update') or '*'
     * @param  string|array  $stepIdentifier  Step ID, key, or '*' for all steps
     * @param  Closure  $callback  Receives flexible parameters
     */
    public function onStepActivatedFor(string $topic, string $action, string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $key                                     = "$topic::$action::$identifier";
            $this->stepActivatedHooks[$key][]        = $callback;
        }
    }

    /**
     * Register a global hook for when a step becomes active (shortcut for onStepActivatedFor('*', '*', ...)).
     *
     * This hook is triggered when an instance moves to a new step, including:
     * - After workflow trigger (first step)
     * - After step transition (next step)
     * - After delay completes (resumed step)
     *
     * Use this for:
     * - Triggering auto-execution of stateless steps
     * - Notifying step assignees
     * - Starting timers or SLAs
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or '*' for all steps
     * @param  Closure  $callback  Receives flexible parameters
     */
    public function onStepActivated(string|array $stepIdentifier, Closure $callback): void
    {
        $this->onStepActivatedFor('*', '*', $stepIdentifier, $callback);
    }

    /**
     * Register a scoped authorization resolver for specific workflows.
     *
     * @param  string  $topic  The topic (e.g., Post::class or custom string) or '*'
     * @param  string  $action  The action (e.g., 'create', 'update') or '*'
     * @param  Closure  $callback  fn(DynaflowStep $step, $user, ?DynaflowInstance $instance): ?bool
     */
    public function authorizeStepFor(string $topic, string $action, Closure $callback): void
    {
        $key                                   = "$topic::$action";
        $this->authorizationResolvers[$key]    = $callback;
    }

    /**
     * Register a scoped exception resolver for specific workflows.
     *
     * @param  string  $topic  The topic or '*'
     * @param  string  $action  The action or '*'
     * @param  Closure  $callback  fn(Dynaflow $workflow, $user): ?bool
     */
    public function exceptionFor(string $topic, string $action, Closure $callback): void
    {
        $key                            = "$topic::$action";
        $this->exceptionResolvers[$key] = $callback;
    }

    /**
     * Register a scoped assignee resolver for specific workflows.
     *
     * @param  string  $topic  The topic or '*'
     * @param  string  $action  The action or '*'
     * @param  Closure  $callback  fn(DynaflowStep $step, $user, ?DynaflowInstance $instance): array
     */
    public function resolveAssigneesFor(string $topic, string $action, Closure $callback): void
    {
        $key                           = "$topic::$action";
        $this->assigneeResolvers[$key] = $callback;
    }

    /**
     * Register a global authorization resolver (shortcut for authorizeStepFor('*', '*', ...)).
     *
     * @param  Closure  $callback  fn(DynaflowStep $step, $user, ?DynaflowInstance $instance): ?bool
     */
    public function authorizeStepUsing(Closure $callback): void
    {
        $this->authorizeStepFor('*', '*', $callback);
    }

    /**
     * Register a global exception resolver (shortcut for exceptionFor('*', '*', ...)).
     *
     * @param  Closure  $callback  fn(Dynaflow $workflow, $user): ?bool
     */
    public function exceptionUsing(Closure $callback): void
    {
        $this->exceptionFor('*', '*', $callback);
    }

    /**
     * Register a global assignee resolver (shortcut for resolveAssigneesFor('*', '*', ...)).
     *
     * @param  Closure  $callback  fn(DynaflowStep $step, $user, ?DynaflowInstance $instance): array
     */
    public function resolveAssigneesUsing(Closure $callback): void
    {
        $this->resolveAssigneesFor('*', '*', $callback);
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

        foreach ($hooks as $hook) {
            $result = $this->invoker->invoke($hook, $available);
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

        $available = $this->buildContextParameters($ctx);

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

            foreach ($this->transitionHooks[$key] as $hook) {
                $result = $this->invoker->invoke($hook, $available);
                if ($result === false) {
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

        foreach ($hooks as $hook) {
            $result = $this->invoker->invoke($hook, $available);
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

        $available = [
            'workflow' => $workflow,
            'instance' => $instance,
            'model'    => $model,
            'user'     => $user,
        ];

        foreach ($hooks as $hook) {
            $this->invoker->invoke($hook, $available);
        }
    }

    /**
     * Alias for authorizeStepFor() - register per-workflow authorization resolver.
     *
     * @param  string  $topic  The topic or '*'
     * @param  string  $action  The action or '*'
     * @param  Closure  $callback  fn(DynaflowStep $step, $user, ?DynaflowInstance $instance): ?bool
     */
    public function authorizeWorkflowStepUsing(string $topic, string $action, Closure $callback): void
    {
        $this->authorizeStepFor($topic, $action, $callback);
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
