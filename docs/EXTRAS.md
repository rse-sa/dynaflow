# Advanced Features

## Field Filtering

Skip workflows based on which fields change.

### Monitored Fields (Whitelist)

Only trigger workflow if specific fields change:

```php
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'monitored_fields' => ['title', 'content', 'status'],
]);
```

Updates to `view_count` or other fields not in the list will skip the workflow.

### Ignored Fields (Blacklist)

Skip workflow if only certain fields change:

```php
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'ignored_fields' => ['view_count', 'last_seen_at'],
]);
```

Updates to only `view_count` will skip the workflow. Updates to `title` will trigger it.

### Using Before Trigger Hooks

For complex conditional logic:

```php
use RSE\DynaFlow\Facades\Dynaflow;

Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->beforeStarting()
    ->execute(function ($workflow, $model, $data, $user) {
        // Skip if only metadata fields changed
        $metadataFields = ['view_count', 'likes_count'];
        $originalData = $model->only(array_keys($data));
        $changedFields = array_keys(array_diff_assoc($data, $originalData));
        $onlyMetadata = empty(array_diff($changedFields, $metadataFields));

        if ($onlyMetadata) {
            return false;  // Skip workflow
        }

        // Skip if admin and single field change
        if ($user->hasRole('admin') && count($data) === 1) {
            return false;
        }

        return true;  // Trigger workflow
    });
```

## Workflow Status Management

While Dynaflow allows any string value for workflow status, using the recommended enum cases provides type safety and better IDE autocomplete.

### Recommended Status Cases

```php
use RSE\DynaFlow\Enums\DynaflowStatus;

// Available status cases
DynaflowStatus::PENDING       // 'pending'       - Active workflow awaiting action
DynaflowStatus::COMPLETED     // 'completed'     - Generic completion (legacy)
DynaflowStatus::APPROVED      // 'approved'      - Explicitly approved
DynaflowStatus::REJECTED      // 'rejected'      - Explicitly rejected
DynaflowStatus::AUTO_APPROVED // 'auto_approved' - Auto-approved (bypass mode)
DynaflowStatus::AUTO_REJECTED // 'auto_rejected' - Auto-rejected (timeout)
DynaflowStatus::CANCELLED     // 'cancelled'     - Cancelled by user
```

### When to Use Each Status

| Status | When to Use |
|--------|-------------|
| `pending` | Default status when workflow starts |
| `completed` | Generic success (legacy, prefer `approved` for clarity) |
| `approved` | Final step approved with positive outcome |
| `rejected` | Final step rejected or workflow terminated negatively |
| `auto_approved` | Workflow bypassed for exception user (direct_complete/auto_follow) |
| `auto_rejected` | Step timeout auto-rejection |
| `cancelled` | User cancelled workflow mid-process |

### Using Enum Cases in Code

Instead of hardcoded strings:

```php
// Before (not recommended)
$instance->update(['status' => 'approved']);
if ($instance->status === 'rejected') { ... }

// After (recommended) - use enum for setting, helper for checking
use RSE\DynaFlow\Enums\DynaflowStatus;

$instance->update(['status' => DynaflowStatus::APPROVED->value]);
if ($instance->isRejected()) { ... }  // Use helper method
```

### Helper Methods on DynaflowInstance

The model provides semantic helper methods:

```php
// Individual checks
$instance->isPending();        // pending
$instance->isApproved();       // completed OR approved OR auto_approved
$instance->isCompleted();      // same as isApproved()
$instance->isRejected();       // rejected OR auto_rejected
$instance->isAutoRejected();   // auto_rejected specifically
$instance->isCancelled();      // cancelled
$instance->isAutoApproved();   // auto_approved
$instance->isTerminated();     // cancelled OR rejected OR auto_rejected
```

### Query Scopes

```php
// Query by status group
DynaflowInstance::pending()->get();
DynaflowInstance::approved()->get();       // completed, approved, auto_approved
DynaflowInstance::rejected()->get();       // rejected, auto_rejected
DynaflowInstance::terminated()->get();     // cancelled, rejected, auto_rejected
DynaflowInstance::cancelled()->get();
DynaflowInstance::autoApproved()->get();
```

### Static Enum Helper Methods

```php
use RSE\DynaFlow\Enums\DynaflowStatus;

// Get all success/failure statuses
$successStatuses = DynaflowStatus::getSuccessStatuses(); // ['completed', 'approved', 'auto_approved']
$failureStatuses = DynaflowStatus::getFailureStatuses(); // ['rejected', 'auto_rejected', 'cancelled']

// Check any status string
if (DynaflowStatus::isSuccessful($someStatus)) { ... }
if (DynaflowStatus::isFailure($someStatus)) { ... }
```

### Setting Final Step Status

Use the `workflow_status` field on final steps:

```php
use RSE\DynaFlow\Enums\DynaflowStatus;

$finalStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'approved',
    'name' => ['en' => 'Approved'],
    'is_final' => true,
    'workflow_status' => DynaflowStatus::APPROVED->value,
]);
```

If `workflow_status` is not set, the decision value becomes the instance status.

### Checking Status in Hooks

```php
use RSE\DynaFlow\Enums\DynaflowStatus;
use RSE\DynaFlow\Support\DynaflowContext;

Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        // Use helper methods instead of raw status checks
        if ($ctx->instance->isApproved()) {
            // Workflow completed successfully
            $ctx->model()->update($ctx->pendingData());
        }

        if ($ctx->instance->isRejected()) {
            // Workflow was rejected at final step
            // Maybe notify or log
        }
    });
```

### Important: whenCompleted vs whenCancelled

Understanding when each hook executes is critical:

| Hook | Executes When |
|------|---------------|
| `whenCompleted` | When workflow reaches a **final step** (`is_final=true`) — **regardless of decision** |
| `whenCancelled` | Only when `cancelWorkflow()` is **explicitly called** |

**Key behaviors:**

1. **Final step with decision="rejected"** → `whenCompleted` runs (NOT `whenCancelled`)
   ```php
   // This triggers whenCompleted hook
   $engine->transitionTo($instance, $finalStep, $user, 'rejected');
   // The final step has is_final=true, so workflow "completes" with rejected status
   ```

2. **Explicit cancellation mid-workflow** → `whenCancelled` runs
   ```php
   // This triggers whenCancelled hook
   $engine->cancelWorkflow($instance, $user, 'rejected', 'User withdrew request');
   ```

**Recommended pattern for multi-outcome workflows:**

```php
use RSE\DynaFlow\Enums\DynaflowStatus;
use RSE\DynaFlow\Support\DynaflowContext;

// Handle ALL final outcomes (approved, rejected, etc.)
Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        // Use helper methods to check status
        if ($ctx->instance->isApproved()) {
            $ctx->model()->update($ctx->pendingData());
        }
        elseif ($ctx->instance->isRejected()) {
            // Notify user of rejection
            $ctx->model->notify(new RejectionNotification($ctx->decision));
        }
        elseif ($ctx->instance->isCancelled()) {
            // Handle cancellation (rare in whenCompleted, but possible)
        }
    });

// Handle explicit cancellations (user withdrawal, etc.)
Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCancelled()
    ->execute(function (DynaflowContext $ctx) {
        // Clean up resources
        // Notification::send($ctx->user, new WithdrawnNotification());
    });
```

**Why this distinction matters:**

- `whenCompleted` marks data as applied (`applied = true`)
- `whenCancelled` does NOT mark data as applied
- Final steps always use `whenCompleted` even when rejected
- Use `cancelWorkflow()` to truly abort and trigger `whenCancelled`

## Draft Support

Optional draft records for viewing and editing pending changes.

### Setup

Add columns to your table:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->boolean('is_draft')->default(false)->index();
    $table->unsignedBigInteger('replaces_id')->nullable();
});
```

Add trait to model:

```php
use RSE\DynaFlow\Traits\HasDrafts;

class Post extends Model
{
    use HasDrafts;

    protected $guarded = ['is_draft', 'replaces_id'];
}
```

### Creating Drafts

```php
public function store(Request $request)
{
    $validated = $request->validate([...]);

    // Create draft with relationships
    $draft = Post::create([
        'is_draft' => true,
        'title' => $validated['title'],
        'content' => $validated['content'],
    ]);

    $draft->tags()->sync($validated['tags']);

    // Pass draft to workflow
    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'create',
        model: null,
        data: $validated,
        draft: $draft
    );

    return $this->dynaflowResponse($result);
}

public function update(Request $request, Post $post)
{
    $validated = $request->validate([...]);

    // Create draft that replaces original
    $draft = Post::create([
        'is_draft' => true,
        'replaces_id' => $post->id,
        ...$validated
    ]);

    $draft->tags()->sync($validated['tags']);

    $result = $this->processDynaflow(
        topic: Post::class,
        action: 'update',
        model: $post,
        data: $validated,
        draft: $draft
    );

    return $this->dynaflowResponse($result);
}
```

### Handling Drafts in Hooks

```php
// CREATE - publish draft
Dynaflow::builder()
    ->forWorkflow(Post::class, 'create')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        $snapshot = $ctx->instance->dynaflowData->data;

        if (isset($snapshot['draft_model_id'])) {
            $draft = Post::withDrafts()->find($snapshot['draft_model_id']);
            $draft->update(['is_draft' => false]);
            $ctx->instance->update(['model_id' => $draft->id]);
        } else {
            $post = Post::create($snapshot['data']);
            $ctx->instance->update(['model_id' => $post->id]);
        }
    });

// UPDATE - merge draft into original
Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        $snapshot = $ctx->instance->dynaflowData->data;

        if (isset($snapshot['draft_model_id'])) {
            $draft = Post::withDrafts()->find($snapshot['draft_model_id']);
            $original = $ctx->model();

            // Copy data
            $original->update($draft->only(['title', 'content']));

            // Sync relationships
            $original->tags()->sync($draft->tags->pluck('id'));

            // Delete draft
            $draft->forceDelete();
        } else {
            $ctx->model()->update($snapshot['data']);
        }
    });

// CANCEL - clean up draft (handles rejections, cancellations, etc.)
Dynaflow::builder()
    ->forWorkflow(Post::class, '*')
    ->whenCancelled()
    ->execute(function (DynaflowContext $ctx) {
        $snapshot = $ctx->instance->dynaflowData->data;

        if (isset($snapshot['draft_model_id'])) {
            $draft = Post::withDrafts()->find($snapshot['draft_model_id']);
            $draft?->forceDelete();
        }
});
```

### Displaying Drafts

```php
// Get draft for review
$post = Post::find($id);
$displayModel = $post->getDisplayInstance();  // Returns draft if pending, else original

// Check if has pending changes
if ($post->hasPendingDynaflow()) {
    $changes = $post->getChangesSummary();
}
```

## Duration Limits & Notifications

### Step Duration Limits

Configure duration limits using the `metadata` JSON column:

```php
$step = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
    'metadata' => [
        'max_duration_to_reject' => 24,  // Auto-reject after 24 hours
        'max_duration_to_accept' => 48,  // Auto-accept after 48 hours (if not rejected)
    ],
]);
```

Access metadata in your code:

```php
$maxRejectHours = $step->getMetadata('max_duration_to_reject');
$maxAcceptHours = $step->getMetadata('max_duration_to_accept');

// Or use helper methods
if ($step->shouldAutoReject($hoursSinceLastAction)) {
    // Handle auto-rejection
}

if ($step->shouldAutoAccept($hoursSinceLastAction)) {
    // Handle auto-acceptance
}
```

**Built-in Command:**

The package includes a command to process expired steps. Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('dynaflow:process-expired-steps')
             ->hourly()
             ->withoutOverlapping();
}
```

Run manually:

```bash
php artisan dynaflow:process-expired-steps
```

**Custom Implementation:**

You can also implement your own logic for duration limits:

```php
use RSE\DynaFlow\Enums\DynaflowStatus;

// Custom command or job
$pendingInstances = DynaflowInstance::pending()->get();

foreach ($pendingInstances as $instance) {
    $currentStep = $instance->currentStep;
    $hoursSinceLastAction = /* calculate duration */;

    if ($currentStep->shouldAutoReject($hoursSinceLastAction)) {
        app(DynaflowEngine::class)->cancelWorkflow(
            instance: $instance,
            user: null, // System action
            decision: DynaflowStatus::AUTO_REJECTED->value,
            notes: 'Automatically rejected due to timeout'
        );
    }
}
```

### Email Notifications

Configure notifications using the `metadata` JSON column:

```php
$step = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
    'metadata' => [
        'notify_on_approve' => true,
        'notify_on_reject' => true,
        'notify_on_edit_request' => true,
        'notification_emails' => ['manager@example.com'],
    ],
]);
```

Notifications are sent to step assignees:

```php
DynaflowStepAssignee::create([
    'dynaflow_step_id' => $step->id,
    'assignable_type' => User::class,
    'assignable_id' => $managerId,
]);
```

### Custom Notification Templates

Store notification templates in the `metadata` JSON column:

```php
$step = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'order' => 1,
    'metadata' => [
        'notify_on_approve' => true,
        'notification_subject' => [
            'en' => 'Workflow Step {decision}: {step_name}',
            'ar' => 'خطوة سير العمل {decision}: {step_name}',
        ],
        'notification_message' => [
            'en' => 'The {step_name} for {topic} has been {decision} by {user_name}.',
            'ar' => 'تم {decision} {step_name} لـ {topic} بواسطة {user_name}.',
        ],
    ],
]);
```

### Available Placeholders (Notification Templates)

These placeholders use `{placeholder}` syntax for notification templates:

- `{step_name}` - Step display name
- `{decision}` - Approve/Reject/Request Edit
- `{topic}` - Workflow topic
- `{action}` - Workflow action
- `{workflow_name}` - Workflow name
- `{user_name}` - User who executed step
- `{user_email}` - User email
- `{note}` - Optional execution note
- `{duration}` - Minutes since last action
- `{executed_at}` - Execution timestamp
- `{model_type}` - Model class
- `{model_id}` - Model ID

> **Note:** For action handler configs (email, http, etc.), use the `{{placeholder}}` syntax instead. See [Action Handlers](ACTION_HANDLERS.md#placeholder-system) for full documentation.

## Change Tracking

For models using drafts, track what changed:

```php
$post = Post::find($id);

// Get all changes
$changes = $post->getChangesSummary();
// [
//     'title' => [
//         'old' => 'Original',
//         'new' => 'Updated',
//         'changed' => true
//     ],
//     ...
// ]

// Get only changed fields
$changedFields = $post->getChangedFields();
```

## Step Authorization

### Custom Authorization Logic

Scoped by workflow (recommended):

```php
use RSE\DynaFlow\Facades\Dynaflow;

// Specific workflow
Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->authorizeStepUsing()
    ->execute(function ($step, $user, $instance) {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($step->key === 'manager_review' && $user->hasRole('manager')) {
            return true;
        }

        // Return null to fall back to next resolver
        return null;
    });

// All workflows (wildcard)
Dynaflow::builder()
    ->authorizeStepUsing()
    ->execute(function ($step, $user, $instance) {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return null;
    });
```

Shortcut for global authorization:

```php
// Equivalent to forWorkflow('*', '*', ...)->authorizeStepUsing()
Dynaflow::builder()
    ->authorizeStepUsing()
    ->execute(function ($step, $user, $instance) {
        if ($user->hasRole('admin')) {
            return true;
        }
        return null;
    });
```

### Assign Users to Steps

```php
use RSE\DynaFlow\Models\DynaflowStepAssignee;

// Specific user
DynaflowStepAssignee::create([
    'dynaflow_step_id' => $step->id,
    'assignable_type' => User::class,
    'assignable_id' => $userId,
]);

// Role or group
DynaflowStepAssignee::create([
    'dynaflow_step_id' => $step->id,
    'assignable_type' => Role::class,
    'assignable_id' => $roleId,
]);
```

## Bypass Modes

When users have workflow exceptions, you can control how bypassing works using metadata:

### Available Modes

**1. `manual` (default)** - Skip workflow entirely, no instance created, no audit trail

**2. `direct_complete`** - Jump directly to final step, create minimal audit (one execution record)

**3. `auto_follow`** - Follow complete workflow path step-by-step (linear workflows only)

**4. `custom_steps`** - Follow specific steps you define in metadata

### Configuration

```php
// Jump to final step
$workflow->setBypassMode('direct_complete')->save();

// Follow custom steps
$workflow->setBypassMode('custom_steps', ['manager_review', 'director_approval', 'approved'])->save();

// Or set metadata directly
$workflow->update([
    'metadata' => [
        'bypass' => [
            'mode' => 'auto_follow'
        ]
    ]
]);
```

### Detect Bypass in Hooks

```php
Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        if ($ctx->isBypassed()) {
            // Workflow was bypassed - skip notifications
            Log::info('Auto-approved for user: ' . $ctx->user->name);
        }

        // Same logic regardless of bypass
        $ctx->model()->update($ctx->pendingData());
    });
```

### Check Bypass Before Triggering

```php
use RSE\DynaFlow\Facades\Dynaflow;

if (Dynaflow::willBypass(Post::class, 'update', $user)) {
    // Show "Auto-approved" badge in UI
    return response()->json(['will_bypass' => true]);
}
```

### Wildcard Support

All authorization resolvers support wildcards:

```php
// All actions for Post workflows
Dynaflow::builder()
    ->forWorkflow(Post::class, '*')
    ->authorizeStepUsing()
    ->execute(function ($step, $user, $instance) {
        return $user->canAccessPost($instance->model);
    });

// All topics for 'publish' action
Dynaflow::builder()
    ->forWorkflow('*', 'publish')
    ->authorizeStepUsing()
    ->execute(function ($step, $user, $instance) {
        return $user->hasRole('publisher');
    });
```

**Authorization Priority (most specific wins):**
1. `Topic::action` (exact match - highest)
2. `Topic::*` (topic wildcard)
3. `*::action` (action wildcard)
4. `*::*` (global wildcard)
5. Database assignees (lowest)

### Hook Execution During Bypass

All bypass modes (except `manual`) execute transition hooks:

```php
// These hooks run even during bypass
\RSE\DynaFlow\Facades\Dynaflow::builder()
    ->forWorkflow('*', '*')
    ->whenTransitioning()
    ->to('manager_review')
    ->execute(function (DynaflowContext $ctx) {
        if ($ctx->isBypassed() && !$ctx->model()->isValid()) {
            // Block bypass if requirements not met
            return false;
        }
    });

Dynaflow::builder()
    ->whenTransitioning()
    ->to('final_approval')
    ->execute(function (DynaflowContext $ctx) {
        if ($ctx->isBypassed()) {
            // Maybe skip email to approvers
            return;
        }

    // Send notification
    Mail::to($assignees)->send(new ApprovalNotification());
});
```

### Requirements & Validation

**For `direct_complete` mode:**
- Workflow MUST have a final step (`is_final = true`)

**For `auto_follow` mode:**
- Workflow MUST be linear (no branching)
- Each non-final step must have exactly one allowed transition
- Will throw exception if branching detected

**For `custom_steps` mode:**
- Step keys must exist in workflow
- Last step MUST be final step
- Steps array must not be empty

### Audit Trail

All bypassed executions have `bypassed=true` flag:

```php
// Query bypassed executions
$bypassedExecutions = DynaflowStepExecution::where('bypassed', true)->get();

// Check in code
if ($execution->bypassed) {
    // This step was auto-executed during bypass
}
```

### Testing Bypass Modes

Factory helpers for easy testing:

```php
use RSE\DynaFlow\Models\Dynaflow;

// Create workflow with bypass mode
$workflow = Dynaflow::factory()->directComplete()->create();
$workflow = Dynaflow::factory()->autoFollow()->create();
$workflow = Dynaflow::factory()->customSteps(['step1', 'step2', 'final'])->create();
```

## Querying Workflows

```php
// Get pending workflows for a model
$pendingWorkflows = $post->pendingDynaflows();

// Check if model has pending workflow
if ($post->hasPendingDynaflow()) {
    // Handle pending state
}

// Get model with pending changes merged
$preview = $post->getWithPendingChanges();
```

## Events

Listen to workflow lifecycle:

```php
use RSE\DynaFlow\Events\DynaflowStarted;
use RSE\DynaFlow\Events\StepTransitioned;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\DynaflowCancelled;

Event::listen(DynaflowStarted::class, function ($event) {
    // Workflow started
    $instance = $event->instance;
});

Event::listen(StepTransitioned::class, function ($event) {
    // Step executed - access full context
    $ctx = $event->context;
    $decision = $ctx->decision;
    $user = $ctx->user;
    $targetStep = $ctx->targetStep;
});

Event::listen(DynaflowCompleted::class, function ($event) {
    // Workflow completed
    $ctx = $event->context;
    $workflowStatus = $ctx->workflowStatus();
});

Event::listen(DynaflowCancelled::class, function ($event) {
    // Workflow cancelled/rejected
    $ctx = $event->context;
    $reason = $ctx->decision;
});
```

## Multiple Workflows per Model

Use different topics for different workflows on the same model:

```php
// Regular updates
$this->processDynaflow(Post::class, 'update', $post, $data);

// Publishing workflow
$this->processDynaflow('PostPublishing', 'update', $post, $data);
```

Register separate hooks:

```php
use RSE\DynaFlow\Support\DynaflowContext;

Dynaflow::builder()
    ->forWorkflow(Post::class, 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        // Standard update
        $ctx->model()->update($ctx->pendingData());
    });

Dynaflow::builder()
    ->forWorkflow('PostPublishing', 'update')
    ->whenCompleted()
    ->execute(function (DynaflowContext $ctx) {
        // Publishing logic
        $ctx->model()->update([
            ...$ctx->pendingData(),
            'published_at' => now(),
        ]);
    });
```

## Debug Logging

Dynaflow can write detailed debug entries for every workflow action to help trace unexpected behavior in development or staging.

### Enabling

Set in your `.env`:

```env
DYNAFLOW_DEBUG=true
```

Or publish and edit `config/dynaflow.php`:

```php
'debug' => env('DYNAFLOW_DEBUG', false),
```

### Log Channel

By default logs go to the application's default channel. Point them elsewhere:

```env
DYNAFLOW_LOG_CHANNEL=daily
```

Any channel defined in `config/logging.php` is accepted (`stack`, `stderr`, `slack`, etc.).

### What Gets Logged

All entries are prefixed with `[Dynaflow]` at the `debug` level.

| Event | Message |
|---|---|
| `trigger()` called | `Trigger requested` |
| No workflow found | `No workflow — applying directly` |
| Workflow resolved | `Workflow resolved` |
| Bypass detected | `Bypass detected` |
| Field filter skips trigger | `Trigger skipped: field filter` |
| `beforeTrigger` hook returns false | `Trigger skipped: beforeTrigger returned false` |
| Instance created | `Instance created` |
| Step becomes active | `Step activated` |
| Hook advanced instance (skips engine continuation) | `Step activation hook advanced instance — skipping engine continuation` |
| Inactive step skipped | `Skipping inactive step` |
| `transitionTo()` called | `Transition requested` |
| Authorization fails | `Authorization failed` |
| Invalid transition | `Invalid transition` |
| Execution record created | `Execution recorded` |
| `beforeTransitionTo` hook blocks | `Transition blocked by beforeTransitionTo hook` |
| `onTransition` hook blocks | `Transition blocked by onTransition hook` |
| Workflow completed | `Workflow completed` |
| Workflow cancelled | `Workflow cancelled` |
| Any hook fires | `Hook firing: <type>` |

### Sample Output

```
[2025-10-31 10:00:00] local.DEBUG: [Dynaflow] Trigger requested {"topic":"App\\Models\\Post","action":"update","model_id":42,"user_id":1}
[2025-10-31 10:00:00] local.DEBUG: [Dynaflow] Workflow resolved {"workflow_id":3,"workflow":"Post Update Approval"}
[2025-10-31 10:00:00] local.DEBUG: [Dynaflow] Instance created {"instance_id":17,"first_step_key":"manager_review"}
[2025-10-31 10:00:00] local.DEBUG: [Dynaflow] Step activated {"instance_id":17,"step_key":"manager_review"}
[2025-10-31 10:00:01] local.DEBUG: [Dynaflow] Transition requested {"instance_id":17,"from":"manager_review","to":"approved","decision":"approved","user_id":2}
[2025-10-31 10:00:01] local.DEBUG: [Dynaflow] Execution recorded {"execution_id":28,"instance_id":17,"step_key":"manager_review","decision":"approved"}
[2025-10-31 10:00:01] local.DEBUG: [Dynaflow] Workflow completed {"instance_id":17,"status":"approved"}
```

### Calling `transitionTo()` from a Hook

A common pattern is auto-advancing steps from inside `whenStepActivated` when no assignees are available. The engine explicitly supports this — after hook execution it reloads the instance and skips its own continuation if the hook already advanced it. The debug log will show:

```
[Dynaflow] Step activation hook advanced instance — skipping engine continuation
```

This means everything is working correctly; it is not an error.

### Test Isolation

`DynaflowHookManager` is a singleton. When writing tests, call `reset()` before each test to clear accumulated hooks:

```php
protected function setUp(): void
{
    parent::setUp();
    app(DynaflowHookManager::class)->reset();
}
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=dynaflow-config
```

Available options:

```php
return [
    // Custom model classes (must extend base models)
    'models' => [
        'instance' => \RSE\DynaFlow\Models\DynaflowInstance::class,
        'data' => \RSE\DynaFlow\Models\DynaflowData::class,
    ],

    'route_prefix'  => env('WORKFLOW_ROUTE_PREFIX', 'workflows'),
    'middleware'     => ['web', 'auth'],

    // Debug logging
    'debug'          => env('DYNAFLOW_DEBUG', false),
    'log_channel'    => env('DYNAFLOW_LOG_CHANNEL', null),
];
```

### Custom Models

Extend `DynaflowInstance` or `DynaflowData` to add custom relationships:

```php
// config/dynaflow.php
'models' => [
    'instance' => \App\Models\CustomDynaflowInstance::class,
    'data' => \App\Models\CustomDynaflowData::class,
],

// App\Models\CustomDynaflowInstance.php
class CustomDynaflowInstance extends \RSE\DynaFlow\Models\DynaflowInstance
{
    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowComment::class, 'dynaflow_instance_id');
    }
}
```

Custom models must extend their base counterparts.

## Next Steps

- [Quick Start](QUICK_START.md) - Get started quickly
- [Integration](INTEGRATION.md) - Controller integration
- [Hooks](HOOKS.md) - Hook patterns
- [Action Handlers](ACTION_HANDLERS.md) - Step types and auto-execution
