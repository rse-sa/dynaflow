# Dynaflow Integration Guide (Universal Actions)

Complete guide for integrating Dynaflow into your Laravel application with support for ANY action.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Core Concept](#core-concept)
3. [Controller Integration](#controller-integration)
4. [Hook Registration](#hook-registration)
5. [Complete Examples](#complete-examples)
6. [Advanced Usage](#advanced-usage)

---

## Quick Start

### Step 1: Install & Publish

```bash
composer require rse/dynaflow
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

### Step 2: Add Trait to Controller

```php
use RSE\DynaFlow\Traits\UsesDynaflow;

class PostController extends Controller
{
    use UsesDynaflow;
}
```

### Step 3: Register Hooks (AppServiceProvider)

```php
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

public function boot()
{
    // Define what happens when workflow completes
    Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);
        $instance->update(['model_id' => $post->id]);
    });

    Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
        $instance->model->update($instance->dynaflowData->data);
    });

    Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
        $instance->model->delete();
    });
}
```

### Step 4: Use in Controller

```php
public function store(Request $request)
{
    $validated = $request->validate([...]);

    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'create',
        model: null,  // null for create actions
        data: $validated
    );

    return $this->dynaflowResponse($result);
}

public function update(Request $request, Post $post)
{
    $validated = $request->validate([...]);

    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'update',
        model: $post,
        data: $validated
    );

    return $this->dynaflowResponse($result);
}
```

Done! 🎉

---

## Core Concept

Dynaflow is **completely action-agnostic**. It doesn't care what your action is called:
- `create`, `update`, `delete`
- `approve`, `reject`, `publish`
- `archive`, `restore`, `verify`
- Or any custom action name you choose!

**The flow:**

```
Controller → processDynaflow() → Check for workflow
                                     ↓
                    ┌────────────────┴────────────────┐
                    ↓                                  ↓
            No workflow exists          Workflow exists & no exception
            or user has exception                     ↓
                    ↓                         Create DynaflowInstance
            Run completion hook                Store data
            immediately                        Wait for approval
            Return model                              ↓
                                              Approval steps...
                                                      ↓
                                              Final step approved
                                                      ↓
                                              Run completion hook
                                              Return instance
```

**You define what happens via hooks:**
- `Dynaflow::onComplete($topic, $action, $callback)` - Runs when approved
- `Dynaflow::onReject($topic, $action, $callback)` - Runs when rejected

---

## Controller Integration

### The Universal Method

There's only ONE method you need:

```php
protected function processDynaflow(
    string $topic,      // Usually model class: Post::class
    string $action,     // Any action: 'create', 'approve', 'publish', etc.
    ?Model $model,      // The model instance (null for actions without a model)
    array $data,        // Data for the action
    $user = null        // User performing action (defaults to auth()->user())
): mixed
```

### Standard CRUD Operations

```php
class PostController extends Controller
{
    use UsesDynaflow;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required',
            'content' => 'required',
        ]);

        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'create',
            model: null,
            data: $validated
        );

        return $this->dynaflowResponse(
            $result,
            directMessage: 'Post created successfully',
            workflowMessage: 'Post creation submitted for approval'
        );
    }

    public function update(Request $request, Post $post)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required',
            'content' => 'sometimes|required',
        ]);

        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'update',
            model: $post,
            data: $validated
        );

        return $this->dynaflowResponse($result);
    }

    public function destroy(Post $post)
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'delete',
            model: $post,
            data: []
        );

        return $this->dynaflowResponse($result);
    }
}
```

### Custom Actions

```php
class PostController extends Controller
{
    use UsesDynaflow;

    public function publish(Post $post)
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'publish',  // Custom action!
            model: $post,
            data: ['published_at' => now()]
        );

        return $this->dynaflowResponse(
            $result,
            directMessage: 'Post published successfully',
            workflowMessage: 'Post publishing submitted for approval'
        );
    }

    public function approve(Post $post)
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'approve',  // Custom action!
            model: $post,
            data: ['approved_by' => auth()->id()]
        );

        return $this->dynaflowResponse($result);
    }

    public function archive(Post $post)
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'archive',  // Custom action!
            model: $post,
            data: ['archived_at' => now()]
        );

        return $this->dynaflowResponse($result);
    }
}
```

---

## Hook Registration

Register hooks in your service provider to define what happens when workflows complete or are rejected.

### Where to Register

Create a dedicated service provider (recommended):

```php
// app/Providers/DynaflowServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

class DynaflowServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerPostHooks();
        $this->registerInvoiceHooks();
        // ... other hooks
    }

    protected function registerPostHooks()
    {
        // Completion hooks
        Dynaflow::onComplete(Post::class, 'create', [$this, 'handlePostCreate']);
        Dynaflow::onComplete(Post::class, 'update', [$this, 'handlePostUpdate']);
        Dynaflow::onComplete(Post::class, 'delete', [$this, 'handlePostDelete']);
        Dynaflow::onComplete(Post::class, 'publish', [$this, 'handlePostPublish']);

        // Rejection hooks
        Dynaflow::onReject(Post::class, 'create', [$this, 'handlePostCreateRejection']);
        Dynaflow::onReject(Post::class, 'publish', [$this, 'handlePostPublishRejection']);
    }

    protected function handlePostCreate($instance, $user)
    {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);

        // IMPORTANT: Update instance with new model ID
        $instance->update(['model_id' => $post->id]);
        $instance->setRelation('model', $post);

        // Optional: notifications, events, etc.
        event(new PostCreated($post));
    }

    protected function handlePostUpdate($instance, $user)
    {
        $post = $instance->model;
        $data = $instance->dynaflowData->data;

        $post->update($data);
    }

    protected function handlePostDelete($instance, $user)
    {
        $instance->model->delete();
    }

    protected function handlePostPublish($instance, $user)
    {
        $post = $instance->model;

        $post->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $user->getKey(),
        ]);

        event(new PostPublished($post));
    }

    protected function handlePostCreateRejection($instance, $user, $decision)
    {
        // Notify the user who tried to create the post
        $instance->triggeredBy->notify(
            new WorkflowRejectedNotification('Post creation rejected')
        );
    }

    protected function handlePostPublishRejection($instance, $user, $decision)
    {
        // Revert post to draft
        $instance->model->update(['status' => 'draft']);
    }
}
```

Register the provider in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\DynaflowServiceProvider::class,
],
```

### Inline Closures (Simple Cases)

```php
// app/Providers/AppServiceProvider.php

public function boot()
{
    Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);
        $instance->update(['model_id' => $post->id]);
    });

    Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
        $instance->model->update($instance->dynaflowData->data);
    });

    Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
        $instance->model->delete();
    });
}
```

---

## Complete Examples

See `docs/HOOK_REGISTRATION.md` for comprehensive hook examples including:
- Standard CRUD operations
- Custom actions (approve, publish, archive)
- Rejection handling
- Wildcard hooks
- Multiple hooks
- Error handling

See `docs/ExampleController.php` for a complete controller example.

---

## Advanced Usage

### Different Workflows for Different Contexts

Use different topics for the same model:

```php
// Regular editing workflow
$this->processDynaflow(Post::class, 'update', $post, $data);

// Separate workflow for publishing
$this->processDynaflow('PostPublishing', 'update', $post, $data);

// Register hooks for each
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    // Standard update logic
});

Dynaflow::onComplete('PostPublishing', 'update', function ($instance, $user) {
    // Publishing-specific logic
});
```

### Conditional Workflow Processing

```php
public function update(Request $request, Post $post)
{
    $validated = $request->validate([...]);

    // Only use workflow for sensitive fields
    if (isset($validated['status']) && $validated['status'] === 'published') {
        $result = $this->processDynaflow(Post::class, 'publish', $post, $validated);
        return $this->dynaflowResponse($result);
    }

    // Apply directly for non-sensitive changes
    $post->update($validated);
    return response()->json(['data' => $post]);
}
```

### Custom Response Handling

```php
public function store(Request $request)
{
    $validated = $request->validate([...]);

    $result = $this->processDynaflow(Post::class, 'create', null, $validated);

    if ($this->requiresWorkflowApproval($result)) {
        // Workflow triggered
        return response()->json([
            'message' => 'Your post is pending approval',
            'workflow_id' => $result->id,
            'next_approver' => $result->currentStep->assignees->first()->assignable,
        ], 202);
    }

    // Applied directly
    return response()->json([
        'message' => 'Post created',
        'post' => $result,
    ], 201);
}
```

### Accessing Data in Hooks

```php
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    // The model being affected
    $post = $instance->model;

    // The pending changes
    $data = $instance->dynaflowData->data;

    // Who triggered the workflow
    $triggeredBy = $instance->triggeredBy;

    // Who approved each step (audit trail)
    foreach ($instance->executions as $execution) {
        $approver = $execution->executedBy;
        $decision = $execution->decision;
        $note = $execution->note;
    }

    // The workflow configuration
    $workflow = $instance->dynaflow;
    $topic = $workflow->topic;
    $action = $workflow->action;
});
```

---

## Testing

```php
use Tests\TestCase;
use App\Models\Post;
use RSE\DynaFlow\Models\Dynaflow;

class PostControllerTest extends TestCase
{
    public function test_create_triggers_workflow_when_configured()
    {
        // Create a workflow
        Dynaflow::factory()->create([
            'topic' => Post::class,
            'action' => 'create',
            'active' => true,
        ]);

        $response = $this->postJson('/api/posts', [
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $response->assertStatus(202)
            ->assertJson(['requires_approval' => true]);

        // Post should NOT exist yet (pending approval)
        $this->assertDatabaseMissing('posts', ['title' => 'Test Post']);

        // Workflow instance should exist
        $this->assertDatabaseHas('dynaflow_instances', [
            'model_type' => Post::class,
            'status' => 'pending',
        ]);
    }

    public function test_create_applies_directly_when_no_workflow()
    {
        $response = $this->postJson('/api/posts', [
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $response->assertStatus(200)
            ->assertJson(['requires_approval' => false]);

        // Post should exist immediately
        $this->assertDatabaseHas('posts', ['title' => 'Test Post']);
    }
}
```

---

## Summary

**Key Points:**

1. **One method**: `processDynaflow($topic, $action, $model, $data, $user)`
2. **Any action**: Use any action name - `create`, `approve`, `publish`, or custom
3. **Hooks define behavior**: Register completion/rejection hooks to define what happens
4. **Flexible topics**: Use model class or custom topic strings
5. **Automatic routing**: Package handles workflow vs direct application automatically

**Next Steps:**

- See `docs/HOOK_REGISTRATION.md` for detailed hook examples
- See `docs/QUICK_START.md` for a 5-minute setup guide
- See `docs/ExampleController.php` for complete controller code
