# Quick Start Guide

Get Dynaflow running in your Laravel application in 5 minutes.

## Installation

```bash
composer require rse-sa/dynaflow
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

## Basic Setup

### Step 1: Register Hooks

Define what happens when workflows complete. Add to `AppServiceProvider`:

```php
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Support\DynaflowContext;
use App\Models\Post;

public function boot()
{
    Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
        $post = Post::create($ctx->pendingData());
        $ctx->instance->update(['model_id' => $post->id]);
    });

    Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
        $ctx->model()->update($ctx->pendingData());
    });

    Dynaflow::onComplete(Post::class, 'delete', function (DynaflowContext $ctx) {
        $ctx->model()->delete();
    });
}
```

### Step 2: Add Trait to Model (Optional)

```php
use RSE\DynaFlow\Traits\HasDynaflows;

class Post extends Model
{
    use HasDynaflows;
}
```

### Step 3: Add Trait to Controller

```php
use RSE\DynaFlow\Traits\UsesDynaflow;

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

        return $this->dynaflowResponse($result);
    }

    public function update(Request $request, Post $post)
    {
        $validated = $request->validate([
            'title' => 'required',
            'content' => 'required',
        ]);

        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'update',
            model: $post,
            data: $validated
        );

        return $this->dynaflowResponse($result);
    }
}
```

### Step 4: Create a Workflow

```php
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
]);

$step1 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
]);

$step2 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'final_approval',
    'name' => ['en' => 'Final Approval'],
    'order' => 2,
    'is_final' => true,
]);

$step1->allowedTransitions()->attach($step2->id);
```

## How It Works

### Without a Workflow

When no workflow exists for the topic/action, the completion hook runs immediately:

```json
{
  "success": true,
  "message": "Post updated",
  "requires_approval": false,
  "data": { "id": 1, "title": "..." }
}
```

### With a Workflow

When a workflow exists, changes are held pending:

```json
{
  "success": true,
  "message": "Post update submitted for approval",
  "requires_approval": true,
  "workflow": {
    "id": 42,
    "status": "pending",
    "current_step": "Manager Review"
  }
}
```

## Custom Actions

Dynaflow works with any action:

```php
public function publish(Post $post)
{
    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'publish',
        model: $post,
        data: ['published_at' => now()]
    );

    return $this->dynaflowResponse($result);
}
```

Register the completion hook:

```php
Dynaflow::onComplete(Post::class, 'publish', function (DynaflowContext $ctx) {
    $ctx->model()->update([
        'status' => 'published',
        'published_at' => now(),
    ]);
});
```

## Bypass Workflows for Specific Users

```php
use RSE\DynaFlow\Models\DynaflowException;

DynaflowException::create([
    'dynaflow_id' => $workflow->id,
    'exceptionable_type' => User::class,
    'exceptionable_id' => $adminUser->id,
]);
```

When this user performs the action, the completion hook runs immediately (bypasses workflow).

## Next Steps

- [Integration Guide](INTEGRATION.md) - Full controller integration and workflow execution
- [Hooks](HOOKS.md) - Detailed hook registration patterns
- [Extras](EXTRAS.md) - Field filtering, drafts, notifications, and more
