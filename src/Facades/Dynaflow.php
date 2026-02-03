<?php

namespace RSE\DynaFlow\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use RSE\DynaFlow\Contracts\ActionHandler;

/**
 * @method static void beforeTransitionTo(string $stepIdentifier, Closure $callback)
 * @method static void afterTransitionTo(string $stepIdentifier, Closure $callback)
 * @method static void onTransition(string $from, string $to, Closure $callback)
 * @method static void onComplete(string $topic, string $action, Closure $callback)
 * @method static void onCancel(string $topic, string $action, Closure $callback)
 * @method static void beforeTrigger(string $topic, string $action, Closure $callback)
 * @method static void afterTrigger(string $topic, string $action, Closure $callback)
 * @method static void onStepActivatedFor(string $topic, string $action, string $stepIdentifier, Closure $callback)
 * @method static void onStepActivated(string $stepIdentifier, Closure $callback)
 * @method static void authorizeStepFor(string $topic, string $action, Closure $callback)
 * @method static void authorizeStepUsing(Closure $callback)
 * @method static void authorizeWorkflowStepUsing(string $topic, string $action, Closure $callback)
 * @method static void exceptionFor(string $topic, string $action, Closure $callback)
 * @method static void exceptionUsing(Closure $callback)
 * @method static void resolveAssigneesFor(string $topic, string $action, Closure $callback)
 * @method static void resolveAssigneesUsing(Closure $callback)
 *                                                              above will be deprecated, better to use Builder
 * @method static bool willBypass(string $topic, string $action, mixed $user)
 * @method static void registerAction(string $key, ActionHandler|Closure|string $handler)
 * @method static ActionHandler|null getActionHandler(string $key)
 * @method static void registerScript(string $key, Closure $script)
 * @method static Closure|null getScript(string $key)
 * @method static array getScriptKeys()
 * @method static void registerAIResolver(string $provider, Closure|string $resolver)
 * @method static Closure|object|null getAIResolver(string $provider)
 * @method static bool hasAIResolver(string $provider)
 * @method static \RSE\DynaFlow\Services\DynaflowHookBuilder builder()
 * @method static \RSE\DynaFlow\Services\DynaflowHookBuilder forWorkflow(string $topic, string $action)
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
