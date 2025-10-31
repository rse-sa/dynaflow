<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use RSE\DynaFlow\Traits\UsesDynaflow;

/**
 * Example controller demonstrating Dynaflow integration with universal actions.
 *
 * This controller shows how to integrate workflow approvals into standard
 * Laravel CRUD operations AND custom actions using the UsesDynaflow trait.
 *
 * IMPORTANT: You must register hooks in your service provider to define
 * what happens when workflows complete or are rejected.
 *
 * See docs/HOOK_REGISTRATION.md for hook registration examples.
 */
class PostController extends Controller
{
    use UsesDynaflow;

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $posts = Post::paginate(15);

        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * This demonstrates the universal processDynaflow method:
     * - topic: Post::class (the model being affected)
     * - action: 'create' (can be ANY action name)
     * - model: null (no model instance for create actions)
     * - data: The validated data
     */
    public function store(Request $request): JsonResponse
    {
        // Validate the request
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
        ]);

        // Process through Dynaflow
        // If no workflow exists or user has exception: Hook runs immediately
        // If workflow exists: Instance created, waits for approval
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'create',
            model: null,  // null for create actions
            data: $validated
        );

        // Return appropriate response
        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post created successfully',
            workflowMessage: 'Post creation submitted for approval',
            directStatus: 201,  // HTTP 201 Created
            workflowStatus: 202 // HTTP 202 Accepted
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Post $post): JsonResponse
    {
        return response()->json($post);
    }

    /**
     * Update the specified resource in storage.
     *
     * This demonstrates:
     * - Partial updates with validation
     * - Using the universal processDynaflow method for updates
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        // Validate the request (all fields optional for partial updates)
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'status' => 'sometimes|in:draft,published',
        ]);

        // Process update action through Dynaflow
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'update',
            model: $post,
            data: $validated
        );

        // Return standardized response
        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post updated successfully',
            workflowMessage: 'Post update submitted for approval'
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * This demonstrates:
     * - Delete operations with workflow
     */
    public function destroy(Post $post): JsonResponse
    {
        // Process delete action through Dynaflow
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'delete',
            model: $post,
            data: []  // No data needed for delete
        );

        // Return standardized response
        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post deleted successfully',
            workflowMessage: 'Post deletion submitted for approval'
        );
    }

    /**
     * Publish a post (custom action).
     *
     * This demonstrates:
     * - Custom actions beyond CRUD
     * - Using ANY action name ('publish' in this case)
     * - Different workflows for different operations on the same model
     */
    public function publish(Post $post): JsonResponse
    {
        // Process CUSTOM 'publish' action through Dynaflow
        // You can use ANY action name - the hook defines what it does
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'publish',  // Custom action!
            model: $post,
            data: ['published_at' => now()]
        );

        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post published successfully',
            workflowMessage: 'Post publishing submitted for approval'
        );
    }

    /**
     * Approve a post (another custom action).
     *
     * This demonstrates that you can have multiple custom actions
     * for the same model, each with its own workflow.
     */
    public function approve(Post $post): JsonResponse
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'approve',  // Another custom action!
            model: $post,
            data: ['approved_by' => auth()->id(), 'approved_at' => now()]
        );

        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post approved successfully',
            workflowMessage: 'Post approval submitted for review'
        );
    }

    /**
     * Archive a post (yet another custom action).
     */
    public function archive(Post $post): JsonResponse
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'archive',
            model: $post,
            data: ['archived_at' => now()]
        );

        return $this->dynaflowResponse(
            result: $result,
            directMessage: 'Post archived successfully',
            workflowMessage: 'Post archival submitted for approval'
        );
    }

    /**
     * Advanced example: Custom response handling.
     *
     * This demonstrates:
     * - Manual checking of workflow vs direct application
     * - Custom response structure
     * - Additional logic based on result type
     */
    public function updateWithCustomResponse(Request $request, Post $post): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'content' => 'required|string',
        ]);

        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'update',
            model: $post,
            data: $validated
        );

        // Check if workflow approval is required
        if ($this->requiresWorkflowApproval($result)) {
            // Result is a DynaflowInstance - workflow was triggered
            return response()->json([
                'message' => 'Your changes have been submitted for approval',
                'workflow' => [
                    'id' => $result->id,
                    'status' => $result->status,
                    'current_step' => $result->currentStep?->name,
                    'triggered_at' => $result->created_at,
                ],
                'next_approver' => $result->currentStep?->assignees->first()?->assignable,
            ], 202);
        }

        // Result is a Post model - changes were applied directly
        return response()->json([
            'message' => 'Post updated successfully',
            'data' => $result->fresh(), // Reload from database
        ], 200);
    }

    /**
     * Bulk delete example.
     *
     * This demonstrates:
     * - Bulk operations with workflow
     * - Handling mixed results (some direct, some workflow)
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_ids' => 'required|array',
            'post_ids.*' => 'exists:posts,id',
        ]);

        $workflows = [];
        $deletedImmediately = [];

        foreach ($validated['post_ids'] as $postId) {
            $post = Post::findOrFail($postId);
            $result = $this->processDynaflow(
                topic: Post::class,
                action: 'delete',
                model: $post,
                data: []
            );

            if ($this->requiresWorkflowApproval($result)) {
                $workflows[] = [
                    'post_id' => $postId,
                    'workflow_id' => $result->id,
                ];
            } else {
                $deletedImmediately[] = $postId;
            }
        }

        return response()->json([
            'message' => 'Bulk delete processed',
            'deleted_immediately' => count($deletedImmediately),
            'pending_approval' => count($workflows),
            'deleted_posts' => $deletedImmediately,
            'workflows' => $workflows,
        ]);
    }

    /**
     * Example: Using custom topic for same model.
     *
     * This demonstrates using different topics for different contexts,
     * allowing you to have separate workflows for the same action/model combo.
     */
    public function publishWithSpecialWorkflow(Post $post): JsonResponse
    {
        // Use custom topic "PostPublishing" instead of Post::class
        // This allows a different workflow for this specific publishing context
        $result = $this->processDynaflow(
            topic: 'PostPublishing',  // Custom topic!
            action: 'update',
            model: $post,
            data: ['status' => 'published', 'published_at' => now()]
        );

        return $this->dynaflowResponse($result);
    }

    /**
     * Example: Conditional workflow processing.
     *
     * This demonstrates applying workflows only for certain conditions.
     */
    public function conditionalUpdate(Request $request, Post $post): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required',
            'content' => 'sometimes|required',
            'status' => 'sometimes|in:draft,published',
        ]);

        // Only use workflow for status changes
        if (isset($validated['status'])) {
            $result = $this->processDynaflow(
                topic: Post::class,
                action: 'update_status',
                model: $post,
                data: $validated
            );

            return $this->dynaflowResponse($result);
        }

        // Apply directly for non-status changes
        $post->update($validated);

        return response()->json([
            'message' => 'Post updated',
            'data' => $post,
        ]);
    }
}

/**
 * REMEMBER: Register hooks in your service provider!
 *
 * Example hook registration (in AppServiceProvider or DynaflowServiceProvider):
 *
 * use RSE\DynaFlow\Facades\Dynaflow;
 * use App\Models\Post;
 *
 * public function boot()
 * {
 *     // CREATE action
 *     Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
 *         $data = $instance->dynaflowData->data;
 *         $post = Post::create($data);
 *         $instance->update(['model_id' => $post->id]);
 *     });
 *
 *     // UPDATE action
 *     Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
 *         $instance->model->update($instance->dynaflowData->data);
 *     });
 *
 *     // DELETE action
 *     Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
 *         $instance->model->delete();
 *     });
 *
 *     // PUBLISH action (custom!)
 *     Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
 *         $instance->model->update([
 *             'status' => 'published',
 *             'published_at' => $instance->dynaflowData->data['published_at'] ?? now(),
 *         ]);
 *     });
 *
 *     // APPROVE action (custom!)
 *     Dynaflow::onComplete(Post::class, 'approve', function ($instance, $user) {
 *         $data = $instance->dynaflowData->data;
 *         $instance->model->update($data);
 *     });
 *
 *     // ARCHIVE action (custom!)
 *     Dynaflow::onComplete(Post::class, 'archive', function ($instance, $user) {
 *         $instance->model->update(['archived_at' => now()]);
 *     });
 *
 *     // REJECTION handling
 *     Dynaflow::onReject(Post::class, 'create', function ($instance, $user, $decision) {
 *         // Notify the user who tried to create the post
 *         $instance->triggeredBy->notify(new PostCreationRejected($decision));
 *     });
 * }
 *
 * See docs/HOOK_REGISTRATION.md for more examples!
 */
