<?php

namespace RSE\DynaFlow\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void beforeTransitionTo(string $stepIdentifier, \Closure $callback)
 * @method static void afterTransitionTo(string $stepIdentifier, \Closure $callback)
 * @method static void onTransition(string $from, string $to, \Closure $callback)
 * @method static void onComplete(string $topic, string $action, \Closure $callback)
 * @method static void onReject(string $topic, string $action, \Closure $callback)
 * @method static void beforeTrigger(string $topic, string $action, \Closure $callback)
 * @method static void afterTrigger(string $topic, string $action, \Closure $callback)
 * @method static void authorizeStepUsing(\Closure $callback)
 * @method static void exceptionUsing(\Closure $callback)
 * @method static void resolveAssigneesUsing(\Closure $callback)
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
