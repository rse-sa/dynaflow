# Dynaflow - Dynamic Workflow Management for Laravel

[![Latest Version](https://img.shields.io/packagist/v/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)
[![License](https://img.shields.io/packagist/l/rse-sa/dynaflow.svg)](https://packagist.org/packages/rse-sa/dynaflow)

A flexible workflow management package for Laravel that adds multi-step approval processes to any model operation.

## Key Features

- **Action-Agnostic** - Works with any operation (create, update, delete, or custom)
- **Step Types** - Stateful (human approval) and stateless (auto-executing) steps
- **Built-in Actions** - Email, HTTP, delays, conditional routing, parallel execution
- **Decision Routing** - Expression-based, script-based, or AI-powered
- **Placeholder System** - Dynamic content with `{{model.field}}` syntax
- **Hook-Based** - Define behavior through completion and cancellation hooks
- **Polymorphic** - Compatible with any Eloquent model
- **Audit Trail** - Complete execution history with duration tracking

## Installation

```bash
composer require rse-sa/dynaflow
php artisan vendor:publish --tag=dynaflow-migrations
php artisan migrate
```

## Quick Start

### 1. Register Hooks

```php
// app/Providers/AppServiceProvider.php
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Support\DynaflowContext;

public function boot()
{
    Dynaflow::builder()
        ->forWorkflow(Post::class, 'create')
        ->whenCompleted()
        ->execute(function (DynaflowContext $ctx) {
            $post = Post::create($ctx->pendingData());
            $ctx->instance->update(['model_id' => $post->id]);
        });

    Dynaflow::builder()
        ->forWorkflow(Post::class, 'update')
        ->whenCompleted()
        ->execute(function (DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

    Dynaflow::builder()
        ->forWorkflow(Post::class, '*')
        ->whenCancelled()
        ->execute(function (DynaflowContext $ctx) {
            // Handle cancellation/rejection
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
        $result = $this->processDynaflow(
            topic: Post::class,
            action: 'create',
            model: null,
            data: $request->validated()
        );

        return $this->dynaflowResponse($result, 'Post created', 'Post submitted for approval');
    }
}
```

### 3. Create Workflow

```php
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Approval'],
    'topic' => Post::class,
    'action' => 'create',
    'active' => true,
]);

$step1 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'type' => 'approval',  // Stateful - requires human
    'order' => 1,
]);

$step2 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'notify_requester',
    'name' => ['en' => 'Notify Requester'],
    'type' => 'notification',  // Stateless - auto-executes
    'action_handler' => 'email',
    'action_config' => [
        'to' => '{{model.user.email}}',
        'subject' => 'Your request was approved',
        'body' => 'Hello {{user.name}}, your post "{{data.title}}" was approved.',
    ],
    'order' => 2,
    'is_final' => true,
]);

$step1->allowedTransitions()->attach($step2->id);
```

## Step Types

**Stateful** (require human interaction): `approval`, `form`, `review`, `multi_choice`

**Stateless** (auto-execute): `action`, `notification`, `http`, `script`, `decision`, `timer`, `parallel`, `join`, `sub_workflow`, `conditional`

## Built-in Action Handlers

| Handler        | Description                               |
|----------------|-------------------------------------------|
| `email`        | Send emails with placeholder support      |
| `http`         | HTTP requests with retries                |
| `delay`        | Pause workflow for duration               |
| `conditional`  | Route based on conditions                 |
| `parallel`     | Fork into branches                        |
| `join`         | Synchronize branches                      |
| `script`       | Execute registered PHP scripts            |
| `sub_workflow` | Trigger child workflows                   |
| `decision`     | Multi-mode routing (expression/script/AI) |

## Placeholder Syntax

Use `{{placeholder}}` in action configs:

- `{{model.*}}` - Model attributes
- `{{user.*}}` - Current user
- `{{data.*}}` - Pending data
- `{{instance.*}}` - Workflow instance
- `{{workflow.*}}` - Workflow definition
- `{{date:format}}` - Current date
- `{{config:key}}` - Config values

## Documentation

- **[Quick Start](docs/QUICK_START.md)** - Get started in 5 minutes
- **[Integration](docs/INTEGRATION.md)** - Controller integration
- **[Hooks](docs/HOOKS.md)** - Hook registration and patterns
- **[Action Handlers](docs/ACTION_HANDLERS.md)** - Step types and auto-execution
- **[Extras](docs/EXTRAS.md)** - Field filtering, drafts, bypass modes

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

## License

MIT License - see [LICENSE](LICENSE) for details
