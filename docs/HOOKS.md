# Hook System

Dynaflow hooks define what happens when workflows complete, are cancelled, or transition between steps.

## Hook Types

### Completion Hooks

Run when a workflow reaches a final step (any final step).

```php
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Support\DynaflowContext;

Dynaflow::onComplete($topic, $action, function (DynaflowContext $ctx) {
    // Access all context data
    $ctx->instance;     // DynaflowInstance
    $ctx->user;         // User who executed
    $ctx->decision;     // Decision string ('approved', 'rejected', etc.)
    $ctx->sourceStep;   // Previous step
    $ctx->targetStep;   // Final step reached
    $ctx->notes;        // Optional notes
    $ctx->execution;    // DynaflowStepExecution record

    // Helper methods
    $ctx->model();           // Get the model being worked on
    $ctx->pendingData();     // Get pending changes
    $ctx->workflowStatus();  // Get workflow status
    $ctx->duration();        // Get execution duration (seconds)

    // Apply changes based on final step or decision
    if ($ctx->decision === 'approved') {
        $ctx->model()->update($ctx->pendingData());
    }
});
```

**Context Properties:**
- `instance` - The DynaflowInstance
- `user` - User who executed the transition
- `decision` - Freeform decision string
- `sourceStep` - The step we came from
- `targetStep` - The step we transitioned to (final step for completion)
- `execution` - The DynaflowStepExecution record created
- `notes` - Optional notes provided
- `data` - Custom context data array

### Cancellation Hooks

Run when a workflow is cancelled (via `cancelWorkflow()` or rejected final step).

```php
Dynaflow::onCancel($topic, $action, function (DynaflowContext $ctx) {
    // Access cancellation context
    $reason = $ctx->decision;  // 'rejected', 'cancelled', 'withdrawn', etc.
    $notes = $ctx->notes;

    // Clean up resources
    // Notify users
    // Log cancellation
});
```

**Note:** `onReject()` has been removed. Use `onCancel()` for all cancellation scenarios. Check `$ctx->decision` to determine the specific reason.

### Before Trigger Hooks

Run before a workflow is triggered. Return `false` to skip the workflow.

```php
Dynaflow::beforeTrigger($topic, $action, function ($workflow, $model, $data, $user) {
    // Custom skip logic
    if ($shouldSkipWorkflow) {
        return false;  // Skip workflow, apply changes directly
    }
    return true;  // Continue with workflow
});
```

**Note:** This hook does NOT use DynaflowContext (it runs before workflow creation).

### Step Hooks

Run before or after specific steps execute.

```php
// Before step execution (can block by returning false)
Dynaflow::beforeStep('manager_review', function (DynaflowContext $ctx) {
    if ($ctx->user->on_leave) {
        return false;  // Block execution
    }
    return true;
});

// After step execution
Dynaflow::afterStep('manager_review', function (DynaflowContext $ctx) {
    // Log, notify, or perform side effects
    Log::info('Step executed', [
        'step' => $ctx->targetStep->name,
        'decision' => $ctx->decision,
        'duration' => $ctx->duration()
    ]);
});
```

### Transition Hooks

Run when transitioning between steps. Can block transitions by returning false.

```php
Dynaflow::onTransition('manager_review', 'final_approval', function (DynaflowContext $ctx) {
    // Validate transition conditions
    if ($ctx->model()->amount > 10000) {
        return true;  // Allow transition
    }
    return false;  // Block transition
});

// Wildcard patterns supported
Dynaflow::onTransition('*', 'final_approval', function (DynaflowContext $ctx) {
    // Runs for ANY transition to final_approval
});

Dynaflow::onTransition('manager_review', '*', function (DynaflowContext $ctx) {
    // Runs for ANY transition from manager_review
});
```

## Registering Hooks

### In Service Provider

Register hooks in `AppServiceProvider::boot()`:

```php
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Support\DynaflowContext;
use App\Models\Post;

public function boot()
{
    // CREATE action
    Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
        $post = Post::create($ctx->pendingData());
        $ctx->instance->update(['model_id' => $post->id]);
    });

    // UPDATE action
    Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
        $ctx->model()->update($ctx->pendingData());
    });

    // DELETE action
    Dynaflow::onComplete(Post::class, 'delete', function (DynaflowContext $ctx) {
        $ctx->model()->delete();
    });

    // CUSTOM action - publish
    Dynaflow::onComplete(Post::class, 'publish', function (DynaflowContext $ctx) {
        $ctx->model()->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    });

    // Cancellation hook
    Dynaflow::onCancel(Post::class, 'create', function (DynaflowContext $ctx) {
        Log::info('Workflow cancelled', [
            'reason' => $ctx->decision,
            'notes' => $ctx->notes
        ]);
    });
}
```

### Wildcard Support

Use wildcards to register hooks for multiple topics or actions:

```php
// All actions for Post
Dynaflow::onComplete(Post::class, '*', function (DynaflowContext $ctx) {
    Cache::forget("post_{$ctx->model()->id}");
});

// All topics for create action
Dynaflow::onComplete('*', 'create', function (DynaflowContext $ctx) {
    activity()->log('Model created via workflow');
});

// All topics and actions
Dynaflow::onComplete('*', '*', function (DynaflowContext $ctx) {
    // Global completion handler
});
```

## Hook Execution Order

Hooks execute in this order:

1. **Before Trigger** - Can skip workflow
2. **Before Step** - Can block step execution
3. **Transition** - Can block transitions
4. **After Step** - Cannot block (side effects only)
5. **Completion/Cancellation** - Final action

Within each hook type, hooks are executed in order:
1. `*::*` (global wildcards)
2. `$topic::*` (topic wildcards)
3. `*::$action` (action wildcards)
4. `$topic::$action` (specific hooks)

## Pattern A: End-State Steps

Use multiple final steps for self-documenting workflows:

```php
// Setup: Create "Approved" and "Rejected" final steps
$approvedStep = DynaflowStep::create([
    'key' => 'approved',
    'name' => ['en' => 'Approved'],
    'is_final' => true,
    'workflow_status' => 'approved'
]);

$rejectedStep = DynaflowStep::create([
    'key' => 'rejected',
    'name' => ['en' => 'Rejected'],
    'is_final' => true,
    'workflow_status' => 'rejected'
]);

// Hook: Handle based on which final step was reached
Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
    if ($ctx->targetStep->key === 'approved') {
        $ctx->model()->update($ctx->pendingData());
    }
    // Rejected step also triggers onComplete, but you can check targetStep
});

// Or use afterStep hooks for specific steps
Dynaflow::afterStep('approved', function (DynaflowContext $ctx) {
    $ctx->model()->update($ctx->pendingData());
});

Dynaflow::afterStep('rejected', function (DynaflowContext $ctx) {
    Mail::to($ctx->instance->triggeredBy)->send(
        new RequestRejected($ctx->notes)
    );
});
```

## Pattern B: Simple Cancellation

Use `cancelWorkflow()` for simpler workflows:

```php
// No "Rejected" step needed - just cancel from controller
$engine->cancelWorkflow(
    instance: $instance,
    user: auth()->user(),
    decision: 'rejected',
    notes: $request->reason
);

// Hook handles cancellation
Dynaflow::onCancel(Post::class, 'update', function (DynaflowContext $ctx) {
    Mail::to($ctx->instance->triggeredBy)->send(
        new RequestRejected($ctx->notes)
    );
});
```

## Working with Context Data

### Accessing Pending Changes

```php
Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
    $pendingData = $ctx->pendingData();  // ['title' => '...', 'content' => '...']

    // Apply changes
    $ctx->model()->update($pendingData);
});
```

### Custom Context Data

Pass custom data when transitioning:

```php
// In controller
$engine->transitionTo(
    instance: $instance,
    targetStep: $approvedStep,
    user: auth()->user(),
    decision: 'approved',
    notes: 'LGTM',
    context: ['ip_address' => $request->ip(), 'reason_code' => 'A1']
);

// In hook
Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
    $ipAddress = $ctx->get('ip_address');
    $reasonCode = $ctx->get('reason_code');

    AuditLog::create([
        'action' => 'approved',
        'ip' => $ipAddress,
        'code' => $reasonCode
    ]);
});
```

### Checking Workflow Information

```php
Dynaflow::onComplete('*', '*', function (DynaflowContext $ctx) {
    $topic = $ctx->topic();          // "App\Models\Post"
    $action = $ctx->action();        // "update"
    $status = $ctx->workflowStatus(); // "approved"
    $duration = $ctx->duration();    // seconds

    Log::info("Workflow completed", [
        'topic' => $topic,
        'action' => $action,
        'status' => $status,
        'duration' => $duration,
        'from_step' => $ctx->sourceStep->name,
        'to_step' => $ctx->targetStep->name
    ]);
});
```

## Advanced Patterns

### Conditional Logic Based on Decision

```php
Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
    match($ctx->decision) {
        'approved' => $ctx->model()->update($ctx->pendingData()),
        'approved_with_conditions' => $ctx->model()->update([
            ...$ctx->pendingData(),
            'requires_review' => true
        ]),
        'escalated' => Mail::to($admin)->send(new EscalatedRequest($ctx)),
        default => null
    };
});
```

### Multi-Step Context

Access previous steps' information:

```php
Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
    // Get all executions for this instance
    $executions = $ctx->instance->executions;

    foreach ($executions as $execution) {
        Log::info('Step executed', [
            'step' => $execution->step->name,
            'decision' => $execution->decision,
            'duration' => $execution->duration,
            'by' => $execution->executedBy->name
        ]);
    }

    // Create the post
    Post::create($ctx->pendingData());
});
```

### Notifications

```php
Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
    // Notify requester
    Mail::to($ctx->instance->triggeredBy)->send(
        new RequestApproved($ctx)
    );

    // Notify all approvers
    foreach ($ctx->instance->executions as $execution) {
        if ($execution->decision === 'approved') {
            Mail::to($execution->executedBy)->send(
                new ThankYouForApproving($ctx)
            );
        }
    }
});

Dynaflow::onCancel(Post::class, 'update', function (DynaflowContext $ctx) {
    Mail::to($ctx->instance->triggeredBy)->send(
        new RequestRejected($ctx)
    );
});
```

## Testing Hooks

```php
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Support\DynaflowContext;

test('completion hook creates post', function () {
    // Register hook
    Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
        Post::create($ctx->pendingData());
    });

    // Create instance with mock context
    $instance = DynaflowInstance::factory()->create();
    $ctx = new DynaflowContext(
        instance: $instance,
        targetStep: DynaflowStep::factory()->create(['is_final' => true]),
        decision: 'approved',
        user: User::factory()->create(),
        sourceStep: DynaflowStep::factory()->create()
    );

    // Execute hook
    app(DynaflowHookManager::class)->runCompleteHooks($ctx);

    // Assert post was created
    expect(Post::count())->toBe(1);
});
```

## Summary

- **All hooks now receive `DynaflowContext`** for complete transition information
- **Decision is freeform** - use any string value that fits your domain
- **Both patterns supported** - End-state steps OR simple cancellation
- **Hooks execute in order** - Global → Topic → Action → Specific
- **Context helpers** - Easy access to model, data, duration, status
