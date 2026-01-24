<?php

namespace RSE\DynaFlow;

use Closure;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\ActionHandlerRegistry;
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

    protected array $stepActivatedHooks = [];

    protected ?Closure $authorizationResolver = null;

    protected ?Closure $exceptionResolver = null;

    protected ?Closure $assigneeResolver = null;

    protected array $workflowAuthorizers = [];

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
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
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
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
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
     * @param  string  $topic  The topic (e.g., Post::class or 'PostPublishing')
     * @param  string  $action  The action (e.g., 'create', 'update', 'approve', 'publish')
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
     * Register a hook to execute when a step becomes active (current step).
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
     * @param  string  $stepIdentifier  Step ID, key, or '*' for all steps
     * @param  Closure  $callback  Receives (DynaflowInstance $instance, DynaflowStep $step, mixed $user)
     */
    public function onStepActivated(string|array $stepIdentifier, Closure $callback): void
    {
        $identifiers = is_array($stepIdentifier) ? $stepIdentifier : [$stepIdentifier];

        foreach ($identifiers as $identifier) {
            $this->stepActivatedHooks[$identifier][] = $callback;
        }
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
        $longKey  = $workflow->action . ':' . $step->key;

        $hooks = array_merge(
            $this->stepActivatedHooks['*'] ?? [],
            $this->stepActivatedHooks[$step->id] ?? [],
            $this->stepActivatedHooks[$step->key] ?? [],
            $this->stepActivatedHooks[$longKey] ?? []
        );

        foreach ($hooks as $hook) {
            $hook($instance, $step, $user);
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
