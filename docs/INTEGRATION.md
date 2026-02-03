# Integration Guide

Complete guide for integrating Dynaflow into your Laravel application.

> **Note:** Package is in beta. Key API features:
> - Use `transitionTo()` to advance workflows
> - Use `cancelWorkflow()` for rejections/cancellations
> - Decision is a freeform string (not enum)
> - **Hooks support flexible parameters** - use type hints, parameter names, or positions
> - See [HOOKS.md](HOOKS.md) for complete hook documentation

## Controller Integration

### Add the Trait

```php
use RSE\DynaFlow\Traits\UsesDynaflow;

class PostController extends Controller
{
    use UsesDynaflow;
}
```

### Use in Your Methods

```php
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

## Creating Workflows

### Basic Workflow Setup

```php
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
]);
```

### Creating Steps

Steps are identified by a unique `key` within the workflow:

```php
$step1 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',           // Unique identifier
    'name' => ['en' => 'Manager Review'], // Display name (translatable)
    'order' => 1,
]);

$step2 = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'final_approval',
    'name' => ['en' => 'Final Approval'],
    'order' => 2,
    'is_final' => true,  // Mark as final step
]);
```

### End-State Steps (Multiple Outcomes)

Use `is_final` and `workflow_status` to create multiple end states:

```php
// Approved step - workflow completes as "approved"
$approvedStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'approved',
    'name' => ['en' => 'Approved'],
    'order' => 3,
    'is_final' => true,
    'workflow_status' => 'approved',  // Custom status value
]);

// Rejected step - workflow completes as "rejected"
$rejectedStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'rejected',
    'name' => ['en' => 'Rejected'],
    'order' => 3,
    'is_final' => true,
    'workflow_status' => 'rejected',
]);
```

**How it works:**
- When a final step (`is_final = true`) is reached, the workflow automatically completes
- The `workflow_status` field sets the instance status (defaults to decision value if not set)
- Completion hooks receive the full context including which final step was reached
- This creates self-documenting workflows with explicit end states

**Example workflow:**
```
Review → Final Approval → Approved (is_final)
                       ↘ Rejected (is_final)
```

### Define Allowed Transitions

Specify which steps can transition to which other steps:

```php
// Manager review can go to final approval
$step1->allowedTransitions()->attach($step2->id);

// For more complex workflows:
$step1->allowedTransitions()->attach([$step2->id, $step3->id]);
```

### Assign Users to Steps

```php
use RSE\DynaFlow\Models\DynaflowStepAssignee;

// Assign specific user
DynaflowStepAssignee::create([
    'dynaflow_step_id' => $step1->id,
    'assignable_type' => User::class,
    'assignable_id' => $managerId,
]);

// Or assign a role/group
DynaflowStepAssignee::create([
    'dynaflow_step_id' => $step1->id,
    'assignable_type' => Role::class,
    'assignable_id' => $managerRoleId,
]);
```

## Executing Workflow Steps

### The transitionTo Method

The `DynaflowEngine::transitionTo()` method handles step transitions:

```php
use RSE\DynaFlow\Services\DynaflowEngine;

$engine = app(DynaflowEngine::class);

$engine->transitionTo(
    instance: $workflowInstance,    // The DynaflowInstance
    targetStep: $nextStep,          // The step to transition to
    user: auth()->user(),
    decision: 'approved',           // Freeform string (any value)
    notes: 'Looks good',            // Optional notes
    context: []                     // Optional custom data
);
```

### Decision Values

Decision is a **freeform string** - you can use any value that fits your domain:

- `'approved'` - Approve and move to next step
- `'rejected'` - For rejected end-state steps
- `'escalated'` - Escalate to higher authority
- `'conditional_approval'` - Approved with conditions
- `'request_edit'` - Request changes
- Any other string value that makes sense for your workflow

### Approving a Step

When you approve a step, the system:

1. Validates user authorization
2. Validates the transition is allowed
3. Creates a `DynaflowStepExecution` record
4. Checks if it's the final step:
   - **If final:** Sets status to `completed`, runs completion hook
   - **If not final:** Updates `current_step_id` to null (ready for next step)

```php
// Example approval controller action
public function approve(DynaflowInstance $instance, Request $request)
{
    $nextStep = DynaflowStep::where('key', $request->next_step_key)
        ->where('dynaflow_id', $instance->dynaflow_id)
        ->firstOrFail();

    $engine = app(DynaflowEngine::class);

    $engine->transitionTo(
        instance: $instance,
        targetStep: $nextStep,
        user: auth()->user(),
        decision: 'approved',
        notes: $request->note
    );

    return response()->json(['message' => 'Step approved']);
}
```

### Rejecting/Cancelling a Workflow

When you cancel a workflow, the system:

1. Creates a `DynaflowStepExecution` record for audit trail
2. Sets instance status to decision value
3. Sets `cancelled_at` timestamp
4. Runs cancellation hook with full context
5. Workflow is terminated

```php
public function reject(DynaflowInstance $instance, Request $request)
{
    $engine = app(DynaflowEngine::class);

    $engine->cancelWorkflow(
        instance: $instance,
        user: auth()->user(),
        decision: 'rejected',
        notes: $request->rejection_reason
    );

    return response()->json(['message' => 'Workflow rejected']);
}
```

### Finding the Next Step

To find which steps can be executed next:

```php
// If instance has a current step, get allowed transitions
if ($instance->currentStep) {
    $allowedNextSteps = $instance->currentStep->allowedTransitions;
} else {
    // No current step means workflow just started or last step completed
    // Get first step
    $allowedNextSteps = $instance->dynaflow->steps()
        ->orderBy('order')
        ->limit(1)
        ->get();
}
```

### Step Authorization

The system validates if a user can execute a step by:

1. Checking custom authorization callback (if registered)
2. Checking if user is assigned to the step
3. Checking if user's role/group is assigned to the step

```php
// Custom authorization
use RSE\DynaFlow\Facades\Dynaflow;

// Scoped to specific workflow
Dynaflow::authorizeStepFor(Post::class, 'update', function ($step, $user, $instance) {
    if ($user->hasRole('admin')) {
        return true;
    }
    return null;
});

// Global authorization (all workflows)
Dynaflow::authorizeStepUsing(function ($step, $user, $instance) {
    if ($user->hasRole('super_admin')) {
        return true;
    }
    return null;
});
```

## Complete Workflow Example

Here's a complete controller for handling workflow execution:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function __construct(
        private DynaflowEngine $engine
    ) {}

    /**
     * Show pending workflow instance
     */
    public function show(DynaflowInstance $instance)
    {
        $instance->load(['dynaflow', 'currentStep', 'executions.executedBy']);

        // Get available next steps
        $nextSteps = $instance->currentStep
            ? $instance->currentStep->allowedTransitions
            : $instance->dynaflow->steps()->orderBy('order')->limit(1)->get();

        return view('workflows.show', [
            'instance' => $instance,
            'nextSteps' => $nextSteps,
        ]);
    }

    /**
     * Approve current step and move to next
     */
    public function approve(DynaflowInstance $instance, Request $request)
    {
        $validated = $request->validate([
            'next_step_key' => 'required|string',
            'note' => 'nullable|string',
        ]);

        $nextStep = DynaflowStep::where('key', $validated['next_step_key'])
            ->where('dynaflow_id', $instance->dynaflow_id)
            ->firstOrFail();

        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $nextStep,
            user: auth()->user(),
            decision: 'approved',
            notes: $validated['note'] ?? null
        );

        return redirect()
            ->route('workflows.index')
            ->with('success', 'Step approved successfully');
    }

    /**
     * Reject workflow
     */
    public function reject(DynaflowInstance $instance, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $this->engine->cancelWorkflow(
            instance: $instance,
            user: auth()->user(),
            decision: 'rejected',
            notes: $validated['reason']
        );

        return redirect()
            ->route('workflows.index')
            ->with('success', 'Workflow rejected');
    }

    /**
     * Cancel workflow
     */
    public function cancel(DynaflowInstance $instance)
    {
        $this->engine->cancelWorkflow(
            instance: $instance,
            user: auth()->user(),
            decision: 'cancelled',
            notes: 'Cancelled by user'
        );

        return redirect()
            ->route('workflows.index')
            ->with('success', 'Workflow cancelled');
    }
}
```

## Workflow Lifecycle

### 1. Workflow Triggered

When `processDynaflow()` is called and a workflow should be triggered:

```php
// Instance created with status 'pending'
$instance = DynaflowInstance::create([
    'dynaflow_id' => $workflow->id,
    'model_type' => Post::class,
    'model_id' => $post->id,
    'triggered_by_type' => User::class,
    'triggered_by_id' => auth()->id(),
    'status' => 'pending',
    'current_step_id' => null,  // No step executed yet
]);

// Data stored separately
DynaflowData::create([
    'instance_id' => $instance->id,
    'data' => $validated,
]);
```

### 2. First Step Approved

```php
$engine->executeStep(
    instance: $instance,
    step: $firstStep,
    decision: DynaflowStepDecision::APPROVE,
    user: $manager
);

// Creates execution record
DynaflowStepExecution::create([
    'instance_id' => $instance->id,
    'step_id' => $firstStep->id,
    'executed_by_type' => User::class,
    'executed_by_id' => $manager->id,
    'decision' => 'approve',
    'note' => 'Approved',
    'duration' => 45,  // minutes since last action
]);

// Updates instance
$instance->update([
    'current_step_id' => null,  // Ready for next step
]);
```

### 3. Final Step Approved

```php
$engine->executeStep(
    instance: $instance,
    step: $finalStep,
    decision: DynaflowStepDecision::APPROVE,
    user: $director
);

// Instance marked complete
$instance->update([
    'status' => 'completed',
    'completed_at' => now(),
]);

// Completion hook runs
$hook = Dynaflow::getCompleteHook($topic, $action);
$hook($instance, $director);

// Changes applied to model
$post->update($instance->dynaflowData->data);
```

### 4. Step Rejected (Workflow Cancelled)

```php
$engine->executeStep(
    instance: $instance,
    step: $anyStep,
    decision: DynaflowStepDecision::REJECT,
    user: $reviewer
);

// Instance marked cancelled
$instance->update([
    'status' => 'cancelled',
    'completed_at' => now(),
]);

// Rejection hook runs
$hook = Dynaflow::getRejectHook($topic, $action);
$hook($instance, $reviewer, 'reject');

// Pending changes discarded
```

## Step Transitions

### Simple Linear Workflow

```php
$step1->allowedTransitions()->attach($step2->id);
$step2->allowedTransitions()->attach($step3->id);
```

Flow: Step 1 → Step 2 → Step 3

### Branching Workflow

```php
$step1->allowedTransitions()->attach([$step2->id, $step3->id]);
$step2->allowedTransitions()->attach($step4->id);
$step3->allowedTransitions()->attach($step4->id);
```

Flow: Step 1 → (Step 2 OR Step 3) → Step 4

### Conditional Transitions

Use hooks to conditionally allow transitions:

```php
Dynaflow::onTransition('manager_review', 'final_approval', function ($from, $to, $instance, $user) {
    // Only allow if certain conditions met
    if ($instance->model->amount > 10000) {
        return true;  // Allow transition
    }
    return false;  // Block transition
});
```

## User Exceptions (Bypass Workflows)

Allow specific users to bypass workflows entirely:

```php
use RSE\DynaFlow\Models\DynaflowException;

// Permanent exception
DynaflowException::create([
    'dynaflow_id' => $workflow->id,
    'exceptionable_type' => User::class,
    'exceptionable_id' => $adminUser->id,
]);

// Time-limited exception
DynaflowException::create([
    'dynaflow_id' => $workflow->id,
    'exceptionable_type' => User::class,
    'exceptionable_id' => $managerId,
    'starts_at' => now(),
    'ends_at' => now()->addDays(7),
]);
```

Or use custom logic:

```php
// Scoped to specific workflow
Dynaflow::exceptionFor(Post::class, 'update', function ($workflow, $user) {
    return $user->isOwnerOf($workflow->model);
});

// Global exception (all workflows)
Dynaflow::exceptionUsing(function ($workflow, $user) {
    return $user->hasRole('super_admin');
});
```

## Next Steps

- [Hooks](HOOKS.md) - Hook registration and usage
- [Extras](EXTRAS.md) - Field filtering, drafts, notifications
