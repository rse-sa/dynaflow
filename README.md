# Dynaflow - Dynamic Workflow Management for Laravel

[![Latest Version](https://img.shields.io/packagist/v/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)
[![License](https://img.shields.io/packagist/l/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)

A powerful, flexible, and **completely action-agnostic** workflow management package for Laravel. Add multi-step approval workflows to ANY operation in your application - create, update, delete, approve, publish, archive, or any custom action you can imagine.

## Features

- ✅ **Universal Actions** - Support for ANY action (not just CRUD)
- ✅ **Hook-Based Execution** - Define what happens when workflows complete or are rejected
- ✅ **Field Filtering** - Skip workflows when only non-important fields change
- ✅ **Conditional Triggers** - Use hooks to dynamically decide when to skip workflows
- ✅ **Controller Integration** - Simple trait for seamless integration
- ✅ **Flexible Topics** - Use model classes or custom topics
- ✅ **Exception System** - Allow specific users to bypass workflows
- ✅ **Step Authorization** - Control who can approve each step
- ✅ **Audit Trail** - Complete execution history with duration tracking
- ✅ **Events** - Laravel events for workflow lifecycle
- ✅ **Multilingual** - Translatable workflow and step names
- ✅ **Polymorphic** - Works with any Eloquent model

## Quick Start

### Installation

```bash
composer require rse-sa/dynaflow
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

### 1. Register Hooks (AppServiceProvider)

Define what happens when workflows complete or are rejected:

```php
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

public function boot()
{
    // What happens when "create" workflow completes
    Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);
        $instance->update(['model_id' => $post->id]);
    });

    // What happens when "update" workflow completes
    Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
        $instance->model->update($instance->dynaflowData->data);
    });

    // What happens when "publish" workflow completes (custom action!)
    Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
        $instance->model->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    });

    // What happens when workflow is rejected
    Dynaflow::onReject(Post::class, 'create', function ($instance, $user, $decision) {
        // Notify user, clean up resources, etc.
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

    // Custom action - publish
    public function publish(Post $post)
    {
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'publish',  // Any custom action!
            model: $post,
            data: []
        );

        return $this->dynaflowResponse($result);
    }
}
```

### 3. Add Trait to Model (Optional)

```php
use RSE\DynaFlow\Traits\HasDynaflows;

class Post extends Model
{
    use HasDynaflows;
}
```

### 4. Create Workflow

```php
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

// Create workflow
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Creation Approval'],
    'topic' => Post::class,
    'action' => 'create',
    'active' => true,
]);

// Create steps
$step1 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
]);

$step2 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'name' => ['en' => 'Final Approval'],
    'order' => 2,
    'is_final' => true,
]);

// Define allowed transitions
$step1->allowedTransitions()->attach($step2->id);
```

That's it! 🎉

## How It Works

```
Controller → processDynaflow() → Check for workflow
                                    ↓
              ┌─────────────────────┴──────────────────┐
              ↓                                         ↓
   No workflow/has exception              Workflow exists
              ↓                                         ↓
   Run completion hook NOW                 Create instance
   Return result (200)                     Store data
                                           Wait for approval
                                           Return instance (202)
                                                  ↓
                                           Approval steps...
                                                  ↓
                                           Final step approved
                                                  ↓
                                           Run completion hook
                                           Apply changes
```

**When there's no workflow configured or the user has an exception:**
- Completion hook executes immediately
- Changes are applied directly
- Returns HTTP 200 with the model

**When a workflow exists:**
- Creates a workflow instance
- Stores pending changes (not applied yet)
- Returns HTTP 202 with workflow info
- When final step is approved → runs completion hook → applies changes

## Key Concepts

### Universal Actions

Dynaflow is **completely action-agnostic**. Use ANY action name:

- Standard CRUD: `'create'`, `'update'`, `'delete'`
- Custom actions: `'approve'`, `'publish'`, `'archive'`, `'restore'`, `'verify'`
- Business actions: `'refund'`, `'ship'`, `'cancel_order'`, `'transfer'`

There are no hardcoded actions - you define what each action does via hooks.

### Hooks

**Completion Hooks** - Execute when workflow is approved:
```php
Dynaflow::onComplete($topic, $action, function ($instance, $user) {
    // Perform the actual action
    // Access data via $instance->dynaflowData->data
    // Access model via $instance->model
});
```

**Rejection Hooks** - Execute when workflow is rejected/cancelled:
```php
Dynaflow::onReject($topic, $action, function ($instance, $user, $decision) {
    // Clean up, notify users, revert state, etc.
    // $decision is 'reject' or 'cancel'
});
```

**Wildcard Support:**
```php
// Apply to all topics for specific action
Dynaflow::onComplete('*', 'approve', function ($instance, $user) {
    // Runs for ANY model with 'approve' action
});

// Apply to all actions for specific topic
Dynaflow::onComplete(Post::class, '*', function ($instance, $user) {
    // Runs for ANY action on Post
});

// Apply to everything
Dynaflow::onComplete('*', '*', function ($instance, $user) {
    // Global hook
});
```

## API Responses

**When applied directly (no workflow or exception):**
```json
{
  "success": true,
  "message": "Post created successfully",
  "requires_approval": false,
  "data": {
    "id": 1,
    "title": "My Post",
    ...
  }
}
```

**When workflow is triggered:**
```json
{
  "success": true,
  "message": "Post creation submitted for approval",
  "requires_approval": true,
  "workflow": {
    "id": 42,
    "status": "pending",
    "current_step": "Manager Review",
    "triggered_at": "2025-10-31T19:00:00Z"
  }
}
```

## Advanced Features

### User Exceptions (Bypass Workflows)

Allow specific users to skip workflows:

```php
use RSE\DynaFlow\Models\DynaflowException;

DynaflowException::create([
    'dynaflow_id' => $workflow->id,
    'exceptionable_type' => User::class,
    'exceptionable_id' => $adminUser->id,
    'starts_at' => now(),
    'ends_at' => now()->addMonth(),
]);
```

Or use custom logic:

```php
Dynaflow::exceptionUsing(function ($workflow, $user) {
    if ($user->hasRole('admin')) {
        return true;  // Bypass workflow
    }
    return null;  // Use database exceptions
});
```

### Step Authorization

Control who can execute each step:

```php
// Assign users to steps
$step->assignees()->create([
    'assignable_type' => User::class,
    'assignable_id' => $managerId,
]);

// Or use custom authorization logic
Dynaflow::authorizeStepUsing(function ($step, $user) {
    return $user->can('execute-workflow-step', $step);
});
```

### Other Hooks

**Before Step Execution:**
```php
Dynaflow::beforeStep('Manager Review', function ($step, $instance, $user) {
    if (!$user->isAvailable()) {
        return false;  // Block execution
    }
});
```

**After Step Execution:**
```php
Dynaflow::afterStep('*', function ($execution) {
    // Send notifications, log activity, etc.
});
```

**On Transition:**
```php
Dynaflow::onTransition('Manager Review', 'Final Approval', function ($from, $to, $instance, $user) {
    // Runs when transitioning between specific steps
});
```

### View Pending Changes

```php
$post = Post::find(1);

if ($post->hasPendingDynaflow()) {
    // Get model with pending changes merged (for preview)
    $preview = $post->getWithPendingChanges();

    // Get all pending workflows
    $workflows = $post->pendingDynaflows();
}
```

### Events

Listen to workflow lifecycle events:

```php
use RSE\DynaFlow\Events\DynaflowTriggered;
use RSE\DynaFlow\Events\DynaflowStepExecuted;
use RSE\DynaFlow\Events\DynaflowCompleted;

Event::listen(DynaflowTriggered::class, function ($event) {
    // Workflow started
    $instance = $event->instance;
});

Event::listen(DynaflowStepExecuted::class, function ($event) {
    // Step executed
    $execution = $event->execution;
});

Event::listen(DynaflowCompleted::class, function ($event) {
    // Workflow completed
    $instance = $event->instance;
});
```

## Frontend Integration

```javascript
async function updatePost(postId, data) {
  const response = await fetch(`/api/posts/${postId}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });

  const result = await response.json();

  if (result.requires_approval) {
    // Workflow triggered - show pending message
    showNotification('Changes submitted for approval');
    redirectTo(`/workflows/${result.workflow.id}`);
  } else {
    // Applied directly - update UI
    showNotification('Post updated successfully');
    updatePostInUI(result.data);
  }
}
```

## Complete Example

See comprehensive examples in:
- **[Quick Start Guide](docs/QUICK_START.md)** - 5-minute setup
- **[Integration Guide](docs/INTEGRATION_GUIDE.md)** - Complete controller integration
- **[Hook Registration](docs/HOOK_REGISTRATION.md)** - Detailed hook examples
- **[Example Controller](docs/ExampleController.php)** - Working controller code

## Configuration

Publish and edit `config/dynaflow.php`:

```php
return [
    'route_prefix' => env('WORKFLOW_ROUTE_PREFIX', 'workflows'),
    'middleware' => ['web', 'auth'],
];
```

**Note:** Dynaflow uses polymorphic relationships, so it works with ANY user model or authenticatable entity - no configuration needed!

## Testing

```bash
composer test
```

## Documentation

- **[QUICK_START.md](docs/QUICK_START.md)** - Get started in 5 minutes
- **[INTEGRATION_GUIDE.md](docs/INTEGRATION_GUIDE.md)** - Complete integration guide
- **[HOOK_REGISTRATION.md](docs/HOOK_REGISTRATION.md)** - Hook examples and patterns
- **[FIELD_FILTERING.md](docs/FIELD_FILTERING.md)** - Skip workflows based on field changes
- **[ExampleController.php](docs/ExampleController.php)** - Working controller example

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

## Why Dynaflow?

**vs Other Workflow Packages:**

- ✅ **Universal** - Not limited to CRUD operations
- ✅ **Hook-Based** - Clean separation of workflow logic and business logic
- ✅ **Flexible Topics** - One model, multiple workflows
- ✅ **Type Safe** - Full PHP 8.1+ type hints
- ✅ **Laravel Native** - Uses Eloquent, events, and Laravel conventions
- ✅ **Well Tested** - Comprehensive test coverage

## License

MIT License - see [LICENSE](LICENSE) for details

## Credits

Developed by [RSE](https://github.com/rse-sa)

## Support

- **Documentation:** [docs/](docs/)
- **Issues:** [GitHub Issues](https://github.com/rse-sa/dynaflow/issues)
