# Dynaflow Hook Registration Guide

This guide explains how to register completion and rejection hooks for your workflows.

## Table of Contents

1. [Overview](#overview)
2. [Where to Register Hooks](#where-to-register-hooks)
3. [Completion Hooks](#completion-hooks)
4. [Rejection Hooks](#rejection-hooks)
5. [Complete Examples](#complete-examples)
6. [Advanced Patterns](#advanced-patterns)

---

## Overview

Dynaflow is **completely action-agnostic**. You can use ANY action name (create, update, delete, approve, publish, archive, etc.), and you define what happens when the workflow completes or is rejected using hooks.

###Key Concepts:

- **Completion Hook**: Executes when a workflow's final step is approved
- **Rejection Hook**: Executes when a workflow is rejected or cancelled
- **Topic**: Usually the model class (e.g., `Post::class` or `App\Models\Post`)
- **Action**: Any string describing the action (e.g., 'create', 'approve', 'publish')

---

## Where to Register Hooks

Register hooks in your `AppServiceProvider` (or a dedicated service provider):

```php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerDynaflowHooks();
    }

    protected function registerDynaflowHooks()
    {
        // Register completion hooks
        Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
            // Your logic here...
        });

        Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
            // Your logic here...
        });

        // Register rejection hooks
        Dynaflow::onReject(Post::class, 'create', function ($instance, $user, $decision) {
            // Your logic here...
        });
    }
}
```

---

## Completion Hooks

Completion hooks execute when a workflow is approved. They receive:
- `$instance` - The `DynaflowInstance` with all workflow data
- `$user` - The user who approved the final step

### Basic Pattern

```php
Dynaflow::onComplete($topic, $action, function ($instance, $user) {
    $data = $instance->dynaflowData->data;
    $model = $instance->model;

    // Perform your action here
});
```

### Example: Create Action

```php
use App\Models\Post;
use RSE\DynaFlow\Facades\Dynaflow;

Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
    $data = $instance->dynaflowData->data;

    // Create the post
    $post = Post::create($data);

    // Update the instance with the newly created model
    $instance->update(['model_id' => $post->id]);
    $instance->setRelation('model', $post);

    // Optional: Send notification
    $instance->triggeredBy->notify(new PostCreatedNotification($post));
});
```

### Example: Update Action

```php
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    $data = $instance->dynaflowData->data;
    $post = $instance->model;

    // Update the post
    $post->update($data);

    // Optional: Log the change
    activity()
        ->performedOn($post)
        ->causedBy($user)
        ->log('Post updated via workflow');
});
```

### Example: Delete Action

```php
Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
    $post = $instance->model;

    // Soft delete or hard delete
    $post->delete();

    // Optional: Clean up related data
    $post->comments()->delete();
    $post->media()->delete();
});
```

### Example: Custom Action - Approve

```php
Dynaflow::onComplete(Post::class, 'approve', function ($instance, $user) {
    $post = $instance->model;
    $data = $instance->dynaflowData->data;

    // Mark as approved
    $post->update([
        'status' => 'approved',
        'approved_by' => $user->getKey(),
        'approved_at' => now(),
    ]);

    // Send notification to author
    $post->author->notify(new PostApprovedNotification($post, $user));
});
```

### Example: Custom Action - Publish

```php
Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    $post = $instance->model;
    $data = $instance->dynaflowData->data;

    // Publish the post
    $post->update([
        'status' => 'published',
        'published_at' => $data['published_at'] ?? now(),
        'published_by' => $user->getKey(),
    ]);

    // Trigger post-publication tasks
    event(new PostPublished($post));

    // Clear caches
    cache()->tags(['posts'])->flush();
});
```

---

## Rejection Hooks

Rejection hooks execute when a workflow is rejected or cancelled. They receive:
- `$instance` - The `DynaflowInstance`
- `$user` - The user who rejected/cancelled
- `$decision` - The decision type ('reject' or 'cancel')

### Basic Pattern

```php
Dynaflow::onReject($topic, $action, function ($instance, $user, $decision) {
    // Clean up, notify, or perform rollback actions
});
```

### Example: Notify on Rejection

```php
Dynaflow::onReject(Post::class, 'create', function ($instance, $user, $decision) {
    $triggeredBy = $instance->triggeredBy;

    // Notify the user who initiated the workflow
    $triggeredBy->notify(new WorkflowRejectedNotification(
        message: "Your post creation request was {$decision}ed",
        rejectedBy: $user,
        reason: $instance->executions()->latest()->first()->note
    ));
});
```

### Example: Clean Up Resources

```php
Dynaflow::onReject(Post::class, 'update', function ($instance, $user, $decision) {
    // Clean up any temporary files uploaded during the workflow
    $data = $instance->dynaflowData->data;

    if (isset($data['image_path'])) {
        Storage::delete($data['image_path']);
    }

    // Log the rejection
    Log::info("Post update workflow rejected", [
        'post_id' => $instance->model_id,
        'rejected_by' => $user->getKey(),
        'decision' => $decision,
    ]);
});
```

### Example: Revert State

```php
Dynaflow::onReject(Post::class, 'approve', function ($instance, $user, $decision) {
    $post = $instance->model;

    // Revert to previous state
    $post->update(['status' => 'draft']);

    // Notify stakeholders
    $post->author->notify(new ApprovalRejectedNotification($post, $user));
});
```

---

## Complete Examples

### Scenario 1: Blog Post Management

```php
// app/Providers/DynaflowServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;
use App\Notifications\PostCreatedNotification;
use App\Notifications\PostRejectedNotification;

class DynaflowServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerPostWorkflows();
    }

    protected function registerPostWorkflows()
    {
        // CREATE: Post creation workflow
        Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
            $data = $instance->dynaflowData->data;

            $post = Post::create(array_merge($data, [
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ]));

            $instance->update(['model_id' => $post->id]);
            $instance->setRelation('model', $post);

            $instance->triggeredBy->notify(new PostCreatedNotification($post));
        });

        Dynaflow::onReject(Post::class, 'create', function ($instance, $user, $decision) {
            $instance->triggeredBy->notify(new PostRejectedNotification(
                action: 'creation',
                reason: $instance->executions()->latest()->first()->note
            ));
        });

        // UPDATE: Post update workflow
        Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
            $post = $instance->model;
            $data = $instance->dynaflowData->data;

            $post->update($data);

            activity()
                ->performedOn($post)
                ->causedBy($user)
                ->withProperties($data)
                ->log('Post updated via workflow');
        });

        Dynaflow::onReject(Post::class, 'update', function ($instance, $user, $decision) {
            // Clean up uploaded images if any
            $data = $instance->dynaflowData->data;
            if (isset($data['featured_image'])) {
                Storage::delete($data['featured_image']);
            }
        });

        // PUBLISH: Post publishing workflow
        Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
            $post = $instance->model;

            $post->update([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $user->getKey(),
            ]);

            event(new PostPublished($post));
            cache()->tags(['posts'])->flush();
        });

        // DELETE: Post deletion workflow
        Dynaflow::onComplete(Post::class, 'delete', function ($instance, $user) {
            $post = $instance->model;

            // Archive before deleting
            PostArchive::create($post->toArray());

            $post->delete();
        });
    }
}
```

### Scenario 2: Invoice Approval System

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Invoice;

class DynaflowServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerInvoiceWorkflows();
    }

    protected function registerInvoiceWorkflows()
    {
        // APPROVE: Invoice approval workflow
        Dynaflow::onComplete(Invoice::class, 'approve', function ($instance, $user) {
            $invoice = $instance->model;

            $invoice->update([
                'status' => 'approved',
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ]);

            // Trigger payment processing
            dispatch(new ProcessInvoicePaymentJob($invoice));

            // Notify accounting
            event(new InvoiceApproved($invoice, $user));
        });

        Dynaflow::onReject(Invoice::class, 'approve', function ($instance, $user, $decision) {
            $invoice = $instance->model;
            $execution = $instance->executions()->latest()->first();

            $invoice->update([
                'status' => 'rejected',
                'rejected_by' => $user->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => $execution->note,
            ]);

            // Notify submitter
            $invoice->submittedBy->notify(new InvoiceRejectedNotification(
                $invoice,
                $execution->note
            ));
        });

        // VOID: Invoice voiding workflow
        Dynaflow::onComplete(Invoice::class, 'void', function ($instance, $user) {
            $invoice = $instance->model;

            $invoice->update([
                'status' => 'voided',
                'voided_by' => $user->getKey(),
                'voided_at' => now(),
            ]);

            // Reverse any payments
            $invoice->payments()->each->reverse();
        });
    }
}
```

---

## Advanced Patterns

### Wildcard Hooks

Register hooks for all topics or all actions:

```php
// Apply to all topics for a specific action
Dynaflow::onComplete('*', 'approve', function ($instance, $user) {
    // This runs for ANY topic with 'approve' action
    Log::info("{$instance->model_type} approved", [
        'model_id' => $instance->model_id,
        'approved_by' => $user->getKey(),
    ]);
});

// Apply to all actions for a specific topic
Dynaflow::onComplete(Post::class, '*', function ($instance, $user) {
    // This runs for ANY action on Post
    cache()->tags(['posts'])->flush();
});

// Apply to all topics and actions
Dynaflow::onComplete('*', '*', function ($instance, $user) {
    // This runs for EVERYTHING
    activity()
        ->performedOn($instance->model)
        ->causedBy($user)
        ->log("Workflow completed: {$instance->dynaflow->action}");
});
```

### Multiple Hooks

You can register multiple hooks for the same topic/action combination:

```php
// First hook: Update the model
Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    $instance->model->update(['status' => 'published']);
});

// Second hook: Send notifications
Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    event(new PostPublished($instance->model));
});

// Third hook: Clear caches
Dynaflow::onComplete(Post::class, 'publish', function ($instance, $user) {
    cache()->tags(['posts'])->flush();
});

// All three will execute in order
```

### Accessing Workflow Data

```php
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    // Access the model
    $post = $instance->model;

    // Access the pending changes
    $data = $instance->dynaflowData->data;

    // Access workflow metadata
    $topic = $instance->dynaflow->topic;          // 'App\Models\Post'
    $action = $instance->dynaflow->action;        // 'update'
    $workflow = $instance->dynaflow;              // Full workflow object

    // Access who triggered the workflow
    $triggeredBy = $instance->triggeredBy;

    // Access all executions (audit trail)
    $executions = $instance->executions;

    // Access specific execution details
    foreach ($instance->executions as $execution) {
        $step = $execution->step;
        $decision = $execution->decision;  // 'approve', 'reject', 'cancel'
        $note = $execution->note;
        $executedBy = $execution->executedBy;
        $duration = $execution->duration_hours;
    }
});
```

### Exception Handling

```php
Dynaflow::onComplete(Post::class, 'create', function ($instance, $user) {
    try {
        $data = $instance->dynaflowData->data;
        $post = Post::create($data);

        $instance->update(['model_id' => $post->id]);
        $instance->setRelation('model', $post);

        // Success notification
        $instance->triggeredBy->notify(new PostCreatedNotification($post));
    } catch (\Exception $e) {
        // Log error
        Log::error('Failed to create post via workflow', [
            'instance_id' => $instance->id,
            'error' => $e->getMessage(),
        ]);

        // Notify about failure
        $instance->triggeredBy->notify(new WorkflowFailedNotification($e->getMessage()));

        // Re-throw if you want the workflow to show as failed
        throw $e;
    }
});
```

---

## Best Practices

1. **Keep hooks focused**: Each hook should do one thing well
2. **Use events**: Dispatch events from hooks for additional processing
3. **Log important actions**: Use activity logging for audit trails
4. **Handle errors gracefully**: Wrap risky operations in try-catch
5. **Update the instance**: For create actions, update the instance with the new model ID
6. **Clear caches**: Remember to clear relevant caches after changes
7. **Send notifications**: Keep users informed about workflow outcomes
8. **Use type hints**: Leverage IDE autocomplete with proper type hints

```php
use App\Models\Post;
use RSE\DynaFlow\Models\DynaflowInstance;

Dynaflow::onComplete(Post::class, 'create', function (DynaflowInstance $instance, $user) {
    /** @var array $data */
    $data = $instance->dynaflowData->data;

    /** @var Post $post */
    $post = Post::create($data);

    $instance->update(['model_id' => $post->id]);
});
```

---

## Summary

- **Register hooks in a service provider** (AppServiceProvider or dedicated provider)
- **Use `Dynaflow::onComplete($topic, $action, $callback)`** for completion logic
- **Use `Dynaflow::onReject($topic, $action, $callback)`** for rejection logic
- **Actions can be anything** - create, update, delete, approve, publish, archive, etc.
- **Wildcards work** - use `'*'` for topic or action to match everything
- **Multiple hooks are supported** - they execute in registration order

The hook system makes Dynaflow completely flexible and action-agnostic!
