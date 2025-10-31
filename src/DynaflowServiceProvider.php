<?php

namespace RSE\DynaFlow;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Services\DynaflowStepVisualizer;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\View\Components\DynaflowSteps;

class DynaflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dynaflow.php', 'dynaflow');

        $this->app->singleton(DynaflowHookManager::class);
        $this->app->singleton(DynaflowValidator::class);
        $this->app->singleton(DynaflowEngine::class);
        $this->app->singleton(DynaflowStepVisualizer::class);

        $this->app->singleton('dynaflow.manager', function ($app) {
            return $app->make(DynaflowHookManager::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'dynaflow');

        Blade::component('dynaflow-steps', DynaflowSteps::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/dynaflow.php' => config_path('dynaflow.php'),
            ], 'dynaflow-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'dynaflow-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/dynaflow'),
            ], 'dynaflow-views');
        }
    }
}
