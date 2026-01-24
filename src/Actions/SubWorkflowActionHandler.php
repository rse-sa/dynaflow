<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\Support\DynaflowContext;
use Throwable;

/**
 * Sub-Workflow Action Handler
 *
 * Triggers a child workflow and optionally waits for completion.
 * Enables workflow composition and reuse.
 */
class SubWorkflowActionHandler implements ActionHandler
{
    public function __construct(
        protected DynaflowEngine $engine,
        protected PlaceholderResolver $placeholders
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $topic = $config['topic'] ?? null;
        $action = $config['action'] ?? null;
        $waitForCompletion = $config['wait_for_completion'] ?? false;

        if (! $topic || ! $action) {
            return ActionResult::failed('Sub-workflow topic and action are required');
        }

        // Resolve placeholders in topic/action
        $topic = $this->placeholders->resolve($topic, $ctx);
        $action = $this->placeholders->resolve($action, $ctx);

        // Find the sub-workflow
        $subWorkflow = Dynaflow::where('topic', $topic)
            ->where('action', $action)
            ->where('active', true)
            ->first();

        if (! $subWorkflow) {
            return ActionResult::failed("Sub-workflow not found: $topic::$action");
        }

        // Prepare data for sub-workflow
        $data = $config['data'] ?? [];
        $data = $this->placeholders->resolveArray($data, $ctx);

        // Get model for sub-workflow
        $model = $ctx->model();
        if (isset($config['model_from'])) {
            // Allow specifying a different model from context
            $modelKey = $config['model_from'];
            $model = $ctx->get($modelKey) ?? $model;
        }

        try {
            // Trigger the sub-workflow
            $result = $this->engine->trigger(
                topic: $topic,
                action: $action,
                model: $model,
                data: $data,
                user: $ctx->user
            );

            // If result is a DynaflowInstance, a workflow was started
            if ($result instanceof \RSE\DynaFlow\Models\DynaflowInstance) {
                // Store sub-workflow reference in parent instance metadata
                $parentMetadata = $ctx->instance->metadata ?? [];
                $parentMetadata['sub_workflows'][$step->key] = [
                    'instance_id' => $result->id,
                    'topic' => $topic,
                    'action' => $action,
                    'started_at' => now()->toIso8601String(),
                ];
                $ctx->instance->update(['metadata' => $parentMetadata]);

                if ($waitForCompletion) {
                    // Return waiting status - will be resumed when sub-workflow completes
                    return ActionResult::waiting([
                        'sub_workflow_instance_id' => $result->id,
                        'topic' => $topic,
                        'action' => $action,
                        'status' => 'triggered',
                    ]);
                }

                return ActionResult::success([
                    'sub_workflow_instance_id' => $result->id,
                    'topic' => $topic,
                    'action' => $action,
                    'status' => 'triggered',
                ]);
            }

            // No workflow triggered (bypassed or no workflow configured)
            return ActionResult::success([
                'topic' => $topic,
                'action' => $action,
                'status' => 'bypassed',
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return ActionResult::failed("Failed to trigger sub-workflow: {$e->getMessage()}", [
                'topic' => $topic,
                'action' => $action,
                'exception' => $e::class,
            ]);
        }
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['topic', 'action'],
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'title' => 'Workflow Topic',
                    'description' => 'The topic of the sub-workflow to trigger (e.g., "App\\Models\\Post").',
                ],
                'action' => [
                    'type' => 'string',
                    'title' => 'Workflow Action',
                    'description' => 'The action of the sub-workflow (e.g., "create", "update").',
                ],
                'data' => [
                    'type' => 'object',
                    'title' => 'Workflow Data',
                    'description' => 'Data to pass to the sub-workflow. Supports placeholders.',
                    'additionalProperties' => true,
                ],
                'model_from' => [
                    'type' => 'string',
                    'title' => 'Model From',
                    'description' => 'Context key to get the model for sub-workflow. Defaults to parent model.',
                ],
                'wait_for_completion' => [
                    'type' => 'boolean',
                    'title' => 'Wait for Completion',
                    'description' => 'If true, parent workflow waits until sub-workflow completes.',
                    'default' => false,
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Sub-Workflow';
    }

    public function getDescription(): string
    {
        return 'Trigger a child workflow';
    }

    public function getCategory(): string
    {
        return 'Flow Control';
    }

    public function getIcon(): string
    {
        return 'layers';
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sub_workflow_instance_id' => ['type' => 'integer', 'description' => 'The ID of the created sub-workflow instance'],
                'topic' => ['type' => 'string', 'description' => 'The sub-workflow topic'],
                'action' => ['type' => 'string', 'description' => 'The sub-workflow action'],
                'status' => ['type' => 'string', 'description' => 'Status: "triggered" or "bypassed"'],
            ],
        ];
    }
}
