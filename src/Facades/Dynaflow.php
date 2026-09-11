<?php

namespace RSE\DynaFlow\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use RSE\DynaFlow\Contracts\ActionHandler;

/**
 * --- Builder API (preferred) ---
 * @method static \RSE\DynaFlow\Services\DynaflowHookBuilder builder()
 * @method static \RSE\DynaFlow\Services\DynaflowHookBuilder forWorkflow(string $topic, string $action)
 *
 * --- Non-registration methods ---
 * @method static bool willBypass(string $topic, string $action, mixed $user)
 * @method static void registerQueryScope(class-string<\RSE\DynaFlow\Contracts\WorkflowQueryScope> $class)
 * @method static array getQueryScopes()
 * @method static void withoutResolveFallback()
 * @method static bool resolveFallbackDisabled()
 * @method static bool hasResolverFor(string $topic, string $action)
 * @method static void registerAction(string $key, ActionHandler|Closure|string $handler)
 * @method static ActionHandler|null getActionHandler(string $key)
 * @method static void registerScript(string $key, Closure $script)
 * @method static Closure|null getScript(string $key)
 * @method static array getScriptKeys()
 * @method static void registerAIResolver(string $provider, Closure|string $resolver)
 * @method static Closure|object|null getAIResolver(string $provider)
 * @method static bool hasAIResolver(string $provider)
 *
 * --- Deprecated: use the builder API instead ---
 * @method static void beforeTransitionTo(string $stepIdentifier, Closure $callback) @deprecated
 * @method static void afterTransitionTo(string $stepIdentifier, Closure $callback) @deprecated
 * @method static void onTransition(string $from, string $to, Closure $callback) @deprecated
 * @method static void onComplete(string $topic, string $action, Closure $callback) @deprecated
 * @method static void onCancel(string $topic, string $action, Closure $callback) @deprecated
 * @method static void beforeTrigger(string $topic, string $action, Closure $callback) @deprecated
 * @method static void afterTrigger(string $topic, string $action, Closure $callback) @deprecated
 * @method static void onStepActivatedFor(string $topic, string $action, string $stepIdentifier, Closure $callback) @deprecated
 * @method static void onStepActivated(string $stepIdentifier, Closure $callback) @deprecated
 * @method static void authorizeStepFor(string $topic, string $action, Closure $callback) @deprecated
 * @method static void authorizeStepUsing(Closure $callback) @deprecated
 * @method static void authorizeWorkflowStepUsing(string $topic, string $action, Closure $callback) @deprecated
 * @method static void exceptionFor(string $topic, string $action, Closure $callback) @deprecated
 * @method static void exceptionUsing(Closure $callback) @deprecated
 * @method static void resolveAssigneesFor(string $topic, string $action, Closure $callback) @deprecated
 * @method static void resolveAssigneesUsing(Closure $callback) @deprecated
 * @method static void registerWorkflowResolver(string $topic, string $action, Closure $callback) @deprecated
 * @method static void resolveWorkflowFor(string $topic, string $action, Closure $callback) @deprecated
 * @method static void resolveWorkflowUsing(Closure $callback) @deprecated
 *
 * @see \RSE\DynaFlow\DynaflowHookManager
 */
class Dynaflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dynaflow.manager';
    }
}
