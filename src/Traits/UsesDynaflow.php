<?php

namespace RSE\DynaFlow\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Services\DynaflowEngine;

/**
 * Trait UsesDynaflow
 *
 * This trait provides a universal method to integrate Dynaflow into your controllers.
 * It supports ANY action (create, update, delete, approve, publish, archive, etc.)
 * and uses hooks to define what happens when workflows complete or are rejected.
 */
trait UsesDynaflow
{
    /**
     * Process any action through Dynaflow.
     *
     * This method will:
     * - Check if a workflow exists for this topic/action combination
     * - Check if user has exception to bypass workflow
     * - Either execute the completion hook directly OR trigger a workflow
     *
     * IMPORTANT: You must register completion hooks using:
     * Dynaflow::builder()->forWorkflow($topic, $action)->whenCompleted()->execute(function($instance, $user) { ... })
     *
     * @param  string  $topic  The topic (usually model class, e.g., Post::class)
     * @param  string  $action  The action (e.g., 'create', 'update', 'delete', 'approve', 'publish', etc.)
     * @param  Model|null  $model  The model (null for actions that don't involve a specific model instance)
     * @param  array  $data  The data for the action
     * @param  mixed|null  $user  The user performing the action (defaults to auth user)
     * @return Model|DynaflowInstance Returns the result (model) or workflow instance
     *
     * @throws \Throwable
     *
     * @example
     * // Create action
     * $result = $this->processDynaflow(Post::class, 'create', null, $validated);
     *
     * // Update action
     * $result = $this->processDynaflow(Post::class, 'update', $post, $validated);
     *
     * // Custom action
     * $result = $this->processDynaflow(Post::class, 'publish', $post, ['published_at' => now()]);
     */
    protected function processDynaflow(
        string $topic,
        string $action,
        ?Model $model = null,
        array $data = [],
        $user = null
    ): mixed {
        $user   = $user ?? auth()->user();
        $engine = app(DynaflowEngine::class);

        return $engine->trigger(
            topic: $topic,
            action: $action,
            model: $model,
            data: $data,
            user: $user
        );
    }

    /**
     * Create a JSON response based on the result of a Dynaflow operation.
     *
     * If the result is a Model (or other object), it was applied directly (no workflow or exception).
     * If the result is a DynaflowInstance, a workflow was triggered.
     *
     * @param  mixed  $result  The result from a Dynaflow operation
     * @param  string  $directMessage  Message to show when applied directly
     * @param  string  $workflowMessage  Message to show when workflow triggered
     * @param  int  $directStatus  HTTP status code for direct application (default: 200)
     * @param  int  $workflowStatus  HTTP status code for workflow (default: 202)
     */
    protected function dynaflowResponse(
        mixed $result,
        string $directMessage = 'Operation completed successfully',
        string $workflowMessage = 'Workflow approval required',
        int $directStatus = 200,
        int $workflowStatus = 202
    ): JsonResponse {
        if ($result instanceof DynaflowInstance) {
            // Workflow was triggered - changes are pending approval
            return response()->json([
                'success'           => true,
                'message'           => $workflowMessage,
                'requires_approval' => true,
                'workflow'          => [
                    'id'           => $result->id,
                    'status'       => $result->status,
                    'current_step' => $result->currentStep?->name,
                    'triggered_at' => $result->created_at,
                ],
            ], $workflowStatus);
        }

        // Changes were applied directly
        return response()->json([
            'success'           => true,
            'message'           => $directMessage,
            'requires_approval' => false,
            'data'              => $result,
        ], $directStatus);
    }

    /**
     * Check if the result requires workflow approval.
     */
    protected function requiresWorkflowApproval(mixed $result): bool
    {
        return $result instanceof DynaflowInstance;
    }
}
