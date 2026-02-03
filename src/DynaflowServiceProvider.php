<?php

namespace RSE\DynaFlow;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Actions\ConditionalActionHandler;
use RSE\DynaFlow\Actions\DecisionActionHandler;
use RSE\DynaFlow\Actions\DelayActionHandler;
use RSE\DynaFlow\Actions\EmailActionHandler;
use RSE\DynaFlow\Actions\HttpActionHandler;
use RSE\DynaFlow\Actions\JoinActionHandler;
use RSE\DynaFlow\Actions\ParallelActionHandler;
use RSE\DynaFlow\Actions\ScriptActionHandler;
use RSE\DynaFlow\Actions\SubWorkflowActionHandler;
use RSE\DynaFlow\Services\ActionHandlerRegistry;
use RSE\DynaFlow\Services\AutoStepExecutor;
use RSE\DynaFlow\Services\CallbackInvoker;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Services\DynaflowStepVisualizer;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\Services\ExpressionEvaluator;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\View\Components\DynaflowSteps;

class DynaflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dynaflow.php', 'dynaflow');

        $this->app->singleton(CallbackInvoker::class);
        $this->app->singleton(DynaflowHookManager::class);
        $this->app->singleton(DynaflowValidator::class);
        $this->app->singleton(DynaflowEngine::class);
        $this->app->singleton(DynaflowStepVisualizer::class);
        $this->app->singleton(ActionHandlerRegistry::class);
        $this->app->singleton(PlaceholderResolver::class);
        $this->app->singleton(ExpressionEvaluator::class);
        $this->app->singleton(AutoStepExecutor::class);

        $this->app->singleton('dynaflow.manager', function ($app) {
            return $app->make(DynaflowHookManager::class);
        });

        $this->app->singleton('dynaflow.actions', function ($app) {
            return $app->make(ActionHandlerRegistry::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'dynaflow');

        Blade::component('dynaflow-steps', DynaflowSteps::class);

        $this->registerBuiltInActionHandlers();

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

        // Symlink skills folder to .claude directory for AI agent access (non-production only)
        if (! app()->isProduction() && is_dir(base_path('.claude'))) {
            $skillsDir   = base_path('.claude/skills');
            $symlinkPath = $skillsDir . '/dynaflow';
            $targetPath  = __DIR__ . '/../.agent/skills/dynaflow';

            if (! is_link($symlinkPath) && is_dir($targetPath)) {
                @mkdir($skillsDir, 0755, true);
                @symlink($targetPath, $symlinkPath);
            }

            // Symlink commands folder
            $commandsDir        = base_path('.claude/commands');
            $commandSymlinkPath = $commandsDir . '/dynaflow';
            $commandTargetPath  = __DIR__ . '/../.agent/commands';

            if (! is_link($commandSymlinkPath) && is_dir($commandTargetPath)) {
                @mkdir($commandsDir, 0755, true);
                @symlink($commandTargetPath, $commandSymlinkPath);
            }
        }
    }

    /**
     * Register built-in action handlers.
     */
    protected function registerBuiltInActionHandlers(): void
    {
        $registry = $this->app->make(ActionHandlerRegistry::class);

        // Communication actions
        $registry->register('email', EmailActionHandler::class);
        $registry->register('http', HttpActionHandler::class);

        // Flow control actions
        $registry->register('delay', DelayActionHandler::class);
        $registry->register('conditional', ConditionalActionHandler::class);
        $registry->register('parallel', ParallelActionHandler::class);
        $registry->register('join', JoinActionHandler::class);
        $registry->register('sub_workflow', SubWorkflowActionHandler::class);
        $registry->register('decision', DecisionActionHandler::class);

        // Code execution
        $registry->register('script', ScriptActionHandler::class);
    }
}
