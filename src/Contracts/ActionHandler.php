<?php

namespace RSE\DynaFlow\Contracts;

use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Interface for step action handlers.
 *
 * Action handlers execute the logic for auto-executing (stateless) steps.
 * Each handler type (email, http, script, etc.) implements this interface
 * to define its execution behavior and configuration schema.
 *
 * Example implementation:
 * ```php
 * class EmailActionHandler implements ActionHandler
 * {
 *     public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
 *     {
 *         $config = $step->action_config;
 *         Mail::to($config['to'])->send(new WorkflowEmail($ctx));
 *         return ActionResult::success(['sent_to' => $config['to']]);
 *     }
 * }
 * ```
 */
interface ActionHandler
{
    /**
     * Execute the action for the given step.
     *
     * @param  DynaflowStep  $step  The step being executed
     * @param  DynaflowContext  $ctx  The workflow context
     * @return ActionResult The result of the execution
     */
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult;

    /**
     * Get the JSON Schema for this handler's configuration.
     *
     * This schema is used by the visual designer to generate
     * configuration forms and validate user input.
     *
     * @return array JSON Schema definition
     */
    public function getConfigSchema(): array;

    /**
     * Get the human-readable label for this handler.
     *
     * @return string Display label (e.g., "Send Email")
     */
    public function getLabel(): string;

    /**
     * Get the description of what this handler does.
     *
     * @return string Description for documentation/UI
     */
    public function getDescription(): string;

    /**
     * Get the category for organizing in the visual designer.
     *
     * Common categories: notification, integration, flow, data, advanced
     *
     * @return string Category identifier
     */
    public function getCategory(): string;

    /**
     * Get the icon identifier for the visual designer.
     *
     * @return string Icon name (e.g., 'mail', 'globe', 'code')
     */
    public function getIcon(): string;

    /**
     * Get the JSON Schema for this handler's output.
     *
     * Describes what data the handler produces in the result,
     * which can be used by subsequent steps.
     *
     * @return array JSON Schema definition for output
     */
    public function getOutputSchema(): array;
}
