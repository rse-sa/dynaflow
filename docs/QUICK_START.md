# Dynaflow Quick Start Guide

Get up and running with Dynaflow in 5 minutes!

## Installation

1. Install via Composer:
```bash
composer require rse/dynaflow
```

2. Publish and run migrations:
```bash
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

3. Publish config (optional):
```bash
php artisan vendor:publish --tag=dynaflow-config
```

## Basic Setup

### Step 1: Register Hooks (REQUIRED!)

**IMPORTANT:** You must register hooks to define what happens when workflows complete or are rejected.

In your `AppServiceProvider` (or create a dedicated `DynaflowServiceProvider`):

```php
// app/Providers/AppServiceProvider.php

use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

public function boot()
{
    // Define what happens when "create" workflow completes
    Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);
        $instance->update(['model_id' => $post->id]);
    });

    // Define what happens when "update" workflow completes
    Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
        $instance->model->update($instance->dynaflowData->data);
    });

    // Define what happens when "delete" workflow completes
    Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
        $instance->model->delete();
    });
}
```

### Step 2: Add Trait to Your Model (Optional)

```php
use RSE\DynaFlow\Traits\HasDynaflows;

class Post extends Model
{
    use HasDynaflows;

    protected $fillable = ['title', 'content', 'status'];
}
```

### Step 3: Add Trait to Your Controller

```php
use RSE\DynaFlow\Traits\UsesDynaflow;

class PostController extends Controller
{
    use UsesDynaflow;
}
```

### Step 4: Update Your Controller Methods

**Before (without Dynaflow):**
```php
public function update(Request $request, Post $post)
{
    $validated = $request->validate([
        'title' => 'required|string',
        'content' => 'required|string',
    ]);

    $post->update($validated);

    return response()->json(['message' => 'Post updated']);
}
```

**After (with Dynaflow):**
```php
public function update(Request $request, Post $post)
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

    return $this->dynaflowResponse(
        $result,
        directMessage: 'Post updated successfully',
        workflowMessage: 'Post update submitted for approval'
    );
}
```

That's it! Your controller now supports workflow approvals.

## What Happens Now?

### Without a Workflow Configured

Everything works exactly as before - the completion hook runs immediately and changes are applied:

```json
{
  "success": true,
  "message": "Post updated successfully",
  "requires_approval": false,
  "data": { ... }
}
```

### With a Workflow Configured

Changes are held for approval:

```json
{
  "success": true,
  "message": "Post update submitted for approval",
  "requires_approval": true,
  "workflow": {
    "id": 42,
    "status": "pending",
    "current_step": "Manager Review",
    "triggered_at": "2025-10-31T19:00:00Z"
  }
}
```

## Creating a Workflow

You can create workflows programmatically or via your admin panel:

```php
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

// Create workflow
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
]);

// Create first step
$step1 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
    'is_final' => false,
]);

// Create final step
$step2 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'name' => ['en' => 'Final Approval'],
    'order' => 2,
    'is_final' => true,
]);

// Define allowed transitions
$step1->allowedTransitions()->attach($step2->id);
```

## All CRUD Operations

```php
class PostController extends Controller
{
    use UsesDynaflow;

    // CREATE
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

    // UPDATE
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

    // DELETE
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

## Custom Actions

Dynaflow supports **ANY action** - not just CRUD:

```php
// Publish action
public function publish(Post $post)
{
    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'publish',  // Custom action!
        model: $post,
        data: []
    );

    return $this->dynaflowResponse($result);
}

// Approve action
public function approve(Post $post)
{
    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'approve',  // Another custom action!
        model: $post,
        data: []
    );

    return $this->dynaflowResponse($result);
}
```

**Remember to register hooks for custom actions:**

```php
// In AppServiceProvider
Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    $instance->model->update([
        'status' => 'published',
        'published_at' => now(),
    ]);
});

Dynaflow::onComplete(Post::class, 'approve', function ($instance, $user) {
    $instance->model->update(['status' => 'approved']);
});
```

## Adding User Exceptions

Allow specific users to bypass workflows:

```php
use RSE\DynaFlow\Models\DynaflowException;

DynaflowException::create([
    'dynaflow_id' => $workflow->id,
    'exceptionable_type' => User::class,
    'exceptionable_id' => $adminUser->id,
]);
```

Now when this user performs the action, the completion hook runs immediately (bypasses workflow).

## Frontend Integration

Handle both response types in your frontend:

```javascript
async function updatePost(postId, data) {
  const response = await fetch(`/api/posts/${postId}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });

  const result = await response.json();

  if (result.requires_approval) {
    showMessage('Changes submitted for approval');
    redirectTo(`/workflows/${result.workflow.id}`);
  } else {
    showMessage('Post updated successfully');
    updateLocalData(result.data);
  }
}
```

## Next Steps

- **Full Integration Guide**: See `docs/INTEGRATION_GUIDE.md`
- **Hook Registration**: See `docs/HOOK_REGISTRATION.md` for comprehensive hook examples
- **Example Controller**: See `docs/ExampleController.php`

## Common Use Cases

### Different Workflows for Different Actions

```php
// Regular updates go through standard workflow
$this->processDynaflow(Post::class, 'update', $post, $data);

// Publishing uses a different workflow
$this->processDynaflow(Post::class, 'publish', $post, $data);
```

Register separate hooks for each:

```php
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    $instance->model->update($instance->dynaflowData->data);
});

Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    $instance->model->update([
        'status' => 'published',
        'published_at' => now(),
    ]);
});
```

### Check if Model Has Pending Changes

```php
if ($post->hasPendingDynaflow()) {
    return response()->json([
        'message' => 'This post has pending changes awaiting approval',
        'pending_changes' => $post->pendingDynaflows()->first()->dynaflowData->data,
    ]);
}
```

### Get Model with Pending Changes Applied

```php
// Get the post with pending changes merged (useful for previews)
$postWithPendingChanges = $post->getWithPendingChanges();
```

## Key Concepts

**Universal Actions:**
- Use ANY action name - `create`, `update`, `delete`, `approve`, `publish`, etc.
- No hardcoded actions - you define what they do via hooks

**Hooks Define Behavior:**
- `Dynaflow::onComplete($topic, $action, $callback)` - What happens when approved
- `Dynaflow::onReject($topic, $action, $callback)` - What happens when rejected

**Automatic Routing:**
- No workflow configured → Hook runs immediately
- User has exception → Hook runs immediately (bypass)
- Workflow exists → Instance created → Wait for approval → Then run hook

## Support

For issues or questions:
- Check the full documentation in `docs/INTEGRATION_GUIDE.md`
- Review hook examples in `docs/HOOK_REGISTRATION.md`
- See the complete controller example in `docs/ExampleController.php`
