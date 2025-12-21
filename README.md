# Dynaflow - Dynamic Workflow Management for Laravel

[![Latest Version](https://img.shields.io/packagist/v/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)
[![License](https://img.shields.io/packagist/l/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)

A flexible workflow management package for Laravel that adds multi-step approval processes to any model operation.

## Key Features

- **Action-Agnostic** - Works with any operation (create, update, delete, or custom actions)
- **Hook-Based** - Define behavior through completion and rejection hooks
- **Polymorphic** - Compatible with any Eloquent model
- **Field Filtering** - Skip workflows based on which fields changed
- **Duration Limits** - Auto-accept or auto-reject steps after specified time
- **Email Notifications** - Configurable notifications for step execution
- **Optional Drafts** - Support for draft records with relationships
- **Audit Trail** - Complete execution history with duration tracking

## Installation

```bash
composer require rse-sa/dynaflow
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

## Quick Start

### 1. Register Hooks

Define what happens when workflows complete or are cancelled:

```php
// app/Providers/AppServiceProvider.php

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

    Dynaflow::onCancel(Post::class, 'create', function (DynaflowContext $ctx) {
        // Handle cancellation (rejection, withdrawal, etc.)
        // Access decision: $ctx->decision
        // Access user: $ctx->user
    });
}
```

### 2. Add Trait to Controller

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

        return $this->dynaflowResponse(
            $result,
            directMessage: 'Post created',
            workflowMessage: 'Post submitted for approval'
        );
    }
}
```

### 3. Create Workflow

```php
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Creation Approval'],
    'topic' => Post::class,
    'action' => 'create',
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

When you call `processDynaflow()`, the system:

1. Checks if a workflow exists for the topic/action
2. Checks if the user has an exception (bypass)
3. Evaluates field filtering rules (for updates)
4. Runs `beforeTrigger` hooks

If workflow should be triggered:
- Creates a `DynaflowInstance` with pending status
- Stores data in `DynaflowData` table
- Returns HTTP 202 with workflow info

If workflow should be bypassed:
- Runs completion hook immediately
- Returns HTTP 200 with model data

## Executing Workflow Steps

### Pattern A: Transition to Final Steps (Structured)

Create end-state steps (Approved, Rejected) for a self-documenting workflow:

```php
use RSE\DynaFlow\Services\DynaflowEngine;

$engine = app(DynaflowEngine::class);

// Approve - transition to "Approved" step (is_final=true)
$approvedStep = DynaflowStep::where('key', 'approved')->first();
$engine->transitionTo(
    instance: $workflowInstance,
    targetStep: $approvedStep,
    user: auth()->user(),
    decision: 'approved',
    notes: 'Looks good'
);

// Reject - transition to "Rejected" step (is_final=true)
$rejectedStep = DynaflowStep::where('key', 'rejected')->first();
$engine->transitionTo(
    instance: $workflowInstance,
    targetStep: $rejectedStep,
    user: auth()->user(),
    decision: 'rejected',
    notes: 'Needs revision'
);
```

### Pattern B: Simple Cancellation (Flexible)

For simpler workflows, cancel directly from any step:

```php
// Reject/cancel workflow from current step
$engine->cancelWorkflow(
    instance: $workflowInstance,
    user: auth()->user(),
    decision: 'rejected',
    notes: 'Needs revision'
);
```

When a final step is reached:
- Status changes to `workflow_status ?? decision`
- Completion hook executes with full context
- Changes are applied to the model

When workflow is cancelled:
- Status changes to `decision` value
- Cancellation hook executes with full context
- Pending changes are discarded

## Custom Actions

Dynaflow supports any action name:

```php
// Publish action
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

// Register hook for publish action
Dynaflow::onComplete(Post::class, 'publish', function (DynaflowContext $ctx) {
    $ctx->model()->update([
        'status' => 'published',
        'published_at' => now(),
    ]);
});
```

## API Responses

**Direct application (no workflow):**
```json
{
  "success": true,
  "message": "Post created",
  "requires_approval": false,
  "data": { "id": 1, "title": "..." }
}
```

**Workflow triggered:**
```json
{
  "success": true,
  "message": "Post submitted for approval",
  "requires_approval": true,
  "workflow": {
    "id": 42,
    "status": "pending",
    "current_step": "Manager Review"
  }
}
```

## Documentation

- **[Quick Start](docs/QUICK_START.md)** - Get started in 5 minutes
- **[Integration Guide](docs/INTEGRATION.md)** - Controller integration and workflow setup
- **[Hooks](docs/HOOKS.md)** - Hook registration and usage
- **[Extras](docs/EXTRAS.md)** - Field filtering, drafts, notifications, and more

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

## License

MIT License - see [LICENSE](LICENSE) for details

## Credits

Developed by [RSE](https://github.com/rse-sa)
