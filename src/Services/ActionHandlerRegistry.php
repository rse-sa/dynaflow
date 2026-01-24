<?php

namespace RSE\DynaFlow\Services;

use Closure;
use InvalidArgumentException;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Registry for action handlers.
 *
 * Manages registration and retrieval of action handlers for auto-executing steps.
 * Supports both class-based handlers and closure-based handlers.
 *
 * Example usage:
 * ```php
 * // Register a class-based handler
 * $registry->register('email', EmailActionHandler::class);
 *
 * // Register a closure-based handler
 * $registry->register('custom', function (DynaflowStep $step, DynaflowContext $ctx) {
 *     // Custom logic
 *     return ActionResult::success();
 * });
 *
 * // Execute a handler
 * $result = $registry->execute($step, $context);
 * ```
 */
class ActionHandlerRegistry
{
    /**
     * Registered handlers keyed by their identifier.
     *
     * @var array<string, ActionHandler|Closure|class-string<ActionHandler>>
     */
    protected array $handlers = [];

    /**
     * Resolved handler instances (cached).
     *
     * @var array<string, ActionHandler>
     */
    protected array $resolvedHandlers = [];

    /**
     * Register an action handler.
     *
     * @param  string  $key  Handler identifier (e.g., 'email', 'http', 'script')
     * @param  ActionHandler|Closure|class-string<ActionHandler>  $handler  Handler instance, class name, or closure
     *
     * @throws InvalidArgumentException If handler is invalid type
     */
    public function register(string $key, ActionHandler|Closure|string $handler): void
    {
        if (is_string($handler) && ! is_subclass_of($handler, ActionHandler::class)) {
            throw new InvalidArgumentException(
                "Handler class '{$handler}' must implement " . ActionHandler::class
            );
        }

        $this->handlers[$key] = $handler;

        // Clear resolved cache if re-registering
        unset($this->resolvedHandlers[$key]);
    }

    /**
     * Check if a handler is registered.
     *
     * @param  string  $key  Handler identifier
     */
    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /**
     * Get a resolved handler instance.
     *
     * @param  string  $key  Handler identifier
     * @return ActionHandler|null Handler instance or null if not found
     */
    public function get(string $key): ?ActionHandler
    {
        if (! $this->has($key)) {
            return null;
        }

        // Return cached instance if available
        if (isset($this->resolvedHandlers[$key])) {
            return $this->resolvedHandlers[$key];
        }

        $handler = $this->handlers[$key];

        // Resolve class-string to instance
        if (is_string($handler)) {
            $handler = app($handler);
        }

        // Wrap closure in anonymous handler
        if ($handler instanceof Closure) {
            $handler = $this->wrapClosure($key, $handler);
        }

        $this->resolvedHandlers[$key] = $handler;

        return $handler;
    }

    /**
     * Execute the handler for a given step.
     *
     * @param  DynaflowStep  $step  The step to execute
     * @param  DynaflowContext  $ctx  The workflow context
     * @return ActionResult The execution result
     *
     * @throws InvalidArgumentException If no handler is registered for the step
     */
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $handlerKey = $step->getActionHandler();

        $handler = $this->get($handlerKey);

        if (! $handler) {
            return ActionResult::failed(
                "No handler registered for '{$handlerKey}'",
                ['step_id' => $step->id, 'step_key' => $step->key]
            );
        }

        return $handler->execute($step, $ctx);
    }

    /**
     * Get all registered handlers.
     *
     * @return array<string, ActionHandler> Resolved handlers keyed by identifier
     */
    public function all(): array
    {
        $handlers = [];

        foreach (array_keys($this->handlers) as $key) {
            $handler = $this->get($key);
            if ($handler) {
                $handlers[$key] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * Get all registered handler keys.
     *
     * @return array<string> List of handler keys
     */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Wrap a closure in an anonymous ActionHandler implementation.
     */
    protected function wrapClosure(string $key, Closure $closure): ActionHandler
    {
        return new class($key, $closure) implements ActionHandler
        {
            public function __construct(
                protected string $key,
                protected Closure $closure
            ) {}

            public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
            {
                $result = ($this->closure)($step, $ctx);

                // Allow closures to return various types
                if ($result instanceof ActionResult) {
                    return $result;
                }

                if (is_array($result)) {
                    return ActionResult::success($result);
                }

                if (is_string($result)) {
                    // Treat string as route target
                    return ActionResult::routeTo($result);
                }

                return ActionResult::success();
            }

            public function getConfigSchema(): array
            {
                return [
                    'type'       => 'object',
                    'properties' => [],
                ];
            }

            public function getLabel(): string
            {
                return ucfirst(str_replace('_', ' ', $this->key));
            }

            public function getDescription(): string
            {
                return "Custom action: {$this->key}";
            }

            public function getCategory(): string
            {
                return 'custom';
            }

            public function getIcon(): string
            {
                return 'code';
            }

            public function getOutputSchema(): array
            {
                return [
                    'type'       => 'object',
                    'properties' => [],
                ];
            }
        };
    }
}
