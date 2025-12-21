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

### Using beforeTrigger Hooks

For complex conditional logic:

```php
use RSE\DynaFlow\Facades\Dynaflow;

Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
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
Dynaflow::onComplete(Post::class, 'create', function (DynaflowContext $ctx) {
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
Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
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
Dynaflow::onCancel(Post::class, '*', function (DynaflowContext $ctx) {
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
// Custom command or job
$pendingInstances = DynaflowInstance::where('status', 'pending')->get();

foreach ($pendingInstances as $instance) {
    $currentStep = $instance->currentStep;
    $hoursSinceLastAction = /* calculate duration */;

    if ($currentStep->shouldAutoReject($hoursSinceLastAction)) {
        app(DynaflowEngine::class)->cancelWorkflow(
            instance: $instance,
            user: null, // System action
            decision: 'auto_rejected',
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

### Available Placeholders

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

```php
use RSE\DynaFlow\Facades\Dynaflow;

Dynaflow::authorizeStepUsing(function ($step, $user) {
    // Custom logic
    if ($user->hasRole('admin')) {
        return true;
    }

    if ($step->key === 'manager_review' && $user->hasRole('manager')) {
        return true;
    }

    // Return null to fall back to assignee check
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
use RSE\DynaFlow\Events\DynaflowTriggered;
use RSE\DynaFlow\Events\StepTransitioned;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\DynaflowCancelled;

Event::listen(DynaflowTriggered::class, function ($event) {
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

Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
    // Standard update
    $ctx->model()->update($ctx->pendingData());
});

Dynaflow::onComplete('PostPublishing', 'update', function (DynaflowContext $ctx) {
    // Publishing logic
    $ctx->model()->update([
        ...$ctx->pendingData(),
        'published_at' => now(),
    ]);
});
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=dynaflow-config
```

Available options:

```php
return [
    'route_prefix' => env('WORKFLOW_ROUTE_PREFIX', 'workflows'),
    'middleware' => ['web', 'auth'],
];
```

## Next Steps

- [Quick Start](QUICK_START.md) - Get started quickly
- [Integration](INTEGRATION.md) - Controller integration
- [Hooks](HOOKS.md) - Hook patterns
