<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Custom Models
    |--------------------------------------------------------------------------
    |
    | Extend the base models to add custom relationships or functionality.
    | Custom models must extend their base counterparts.
    |
    */
    'models' => [
        'instance' => \RSE\DynaFlow\Models\DynaflowInstance::class,
        'data' => \RSE\DynaFlow\Models\DynaflowData::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix to use for all Dynaflow routes (e.g., /workflows/*).
    |
    */
    'route_prefix' => env('WORKFLOW_ROUTE_PREFIX', 'workflows'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware stack to apply to Dynaflow routes.
    |
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, Dynaflow writes detailed debug logs for all workflow
    | actions: triggers, step activations, transitions, completions, hook
    | invocations, and more. Useful for tracing unexpected behavior.
    |
    | Enable in .env: DYNAFLOW_DEBUG=true
    |
    */
    'debug' => env('DYNAFLOW_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The Laravel log channel to write debug logs to. Leave null to use
    | the application's default log channel.
    |
    | Example: 'daily', 'stack', 'stderr'
    |
    */
    'log_channel' => env('DYNAFLOW_LOG_CHANNEL', null),
];
