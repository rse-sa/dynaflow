<?php

return [
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
];
