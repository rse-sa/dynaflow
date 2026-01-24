<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;
use Throwable;

/**
 * Script Action Handler
 *
 * Executes developer-registered PHP scripts.
 * Scripts are registered via Dynaflow::registerScript() for security.
 */
class ScriptActionHandler implements ActionHandler
{
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $scriptKey = $config['script'] ?? null;

        if (! $scriptKey) {
            return ActionResult::failed('No script specified');
        }

        $script = Dynaflow::getScript($scriptKey);

        if (! $script) {
            return ActionResult::failed("Script '$scriptKey' not found. Did you register it with Dynaflow::registerScript()?");
        }

        // Prepare parameters from config, resolving placeholders
        $params = $config['params'] ?? [];

        try {
            // Execute the script
            $result = $script($ctx, $params);

            // Handle different return types
            if ($result instanceof ActionResult) {
                return $result;
            }

            if (is_string($result) && str_starts_with($result, 'route:')) {
                // Return like "route:step_key" routes to that step
                $routeTarget = substr($result, 6);
                return ActionResult::routeTo($routeTarget, ['script_result' => $result]);
            }

            if ($result === false) {
                return ActionResult::failed('Script returned false');
            }

            // Success with result data
            return ActionResult::success([
                'script' => $scriptKey,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return ActionResult::failed("Script execution failed: {$e->getMessage()}", [
                'script' => $scriptKey,
                'exception' => $e::class,
            ]);
        }
    }

    public function getConfigSchema(): array
    {
        // Get available scripts for dropdown
        $availableScripts = Dynaflow::getScriptKeys();

        return [
            'type' => 'object',
            'required' => ['script'],
            'properties' => [
                'script' => [
                    'type' => 'string',
                    'title' => 'Script',
                    'description' => 'The registered script to execute.',
                    'enum' => $availableScripts,
                ],
                'params' => [
                    'type' => 'object',
                    'title' => 'Parameters',
                    'description' => 'Parameters to pass to the script. Supports placeholders.',
                    'additionalProperties' => true,
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'title' => 'Timeout (Seconds)',
                    'description' => 'Maximum execution time for the script.',
                    'default' => 30,
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Execute Script';
    }

    public function getDescription(): string
    {
        return 'Execute a developer-registered PHP script';
    }

    public function getCategory(): string
    {
        return 'Code';
    }

    public function getIcon(): string
    {
        return 'code';
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'script' => ['type' => 'string', 'description' => 'The script that was executed'],
                'result' => ['type' => 'mixed', 'description' => 'The script return value'],
            ],
        ];
    }
}
