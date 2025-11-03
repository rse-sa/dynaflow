# Field Filtering and Conditional Workflow Triggering

This guide explains how to skip workflows based on which fields are being updated, giving you fine-grained control over when workflows are triggered.

## Overview

Dynaflow provides two complementary approaches for conditional workflow triggering:

1. **Field-based filtering** (simple, configuration-based)
2. **beforeTrigger hooks** (flexible, code-based)

## Approach 1: Field-Based Filtering

Configure workflows to only trigger when specific fields change, or to skip when only certain fields change.

### Monitored Fields (Whitelist Approach)

Only trigger the workflow if **any** of the specified fields change:

```php
use App\Models\Post;
use RSE\DynaFlow\Models\Dynaflow;

$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
    'monitored_fields' => ['title', 'content', 'status'], // Only these fields matter
]);
```

**Example:**
- Update `title` → Workflow triggers ✅
- Update `view_count` → Workflow skipped, changes applied directly ✅
- Update `title` AND `view_count` → Workflow triggers ✅

### Ignored Fields (Blacklist Approach)

Skip the workflow if **only** the specified fields change:

```php
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
    'ignored_fields' => ['view_count', 'last_seen_at', 'login_count'], // Skip for these
]);
```

**Example:**
- Update `view_count` → Workflow skipped ✅
- Update `title` → Workflow triggers ✅
- Update `title` AND `view_count` → Workflow triggers (because title is not ignored) ✅

### Using the Model Methods

You can also set these fields programmatically:

```php
$workflow = Dynaflow::where('topic', Post::class)
    ->where('action', 'update')
    ->first();

// Set monitored fields
$workflow->setMonitoredFields(['title', 'content', 'status'])
    ->save();

// Or set ignored fields
$workflow->setIgnoredFields(['view_count', 'last_seen_at'])
    ->save();
```

### Important Notes

- Field filtering **only applies to `update` actions** on existing models
- If both `monitored_fields` and `ignored_fields` are set, `monitored_fields` takes precedence
- If neither is set, the workflow triggers for any field change (default behavior)

## Approach 2: beforeTrigger Hooks

For more complex logic, use `beforeTrigger` hooks. These give you complete control over when workflows should be skipped.

### Basic Usage

Register a hook that inspects the data and returns `false` to skip the workflow:

```php
use RSE\DynaFlow\Facades\Dynaflow;
use App\Models\Post;

// In your service provider's boot() method:
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    // Get the fields that changed
    $originalData = $model->only(array_keys($data));
    $changedFields = array_keys(array_diff_assoc($data, $originalData));

    // Skip workflow if only view_count changed
    if (count($changedFields) === 1 && in_array('view_count', $changedFields)) {
        return false; // Skip workflow, apply changes directly
    }

    return true; // Continue with workflow
});
```

### Advanced Examples

#### 1. Skip for Minor Changes

```php
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    // Define "minor" fields
    $minorFields = ['view_count', 'last_seen_at', 'likes_count'];

    // Get changed fields
    $originalData = $model->only(array_keys($data));
    $changedFields = array_keys(array_diff_assoc($data, $originalData));

    // Skip if only minor fields changed
    $majorChanges = array_diff($changedFields, $minorFields);

    return !empty($majorChanges); // False = skip, True = continue
});
```

#### 2. Skip Based on User Role

```php
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    // Admins can skip workflow for specific field updates
    if ($user->hasRole('admin') && isset($data['featured']) && count($data) === 1) {
        return false; // Admins can directly toggle featured status
    }

    return true;
});
```

#### 3. Skip Based on Value Changes

```php
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    // Skip workflow if status changes from draft to published
    // (automatic publish doesn't need approval)
    if (isset($data['status'])
        && $model->status === 'draft'
        && $data['status'] === 'published'
        && count($data) === 1) {
        return false;
    }

    return true;
});
```

#### 4. Combine Multiple Conditions

```php
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    $originalData = $model->only(array_keys($data));
    $changedFields = array_keys(array_diff_assoc($data, $originalData));

    // Skip if:
    // 1. Only metadata fields changed
    $metadataFields = ['view_count', 'likes_count', 'shares_count'];
    $onlyMetadata = empty(array_diff($changedFields, $metadataFields));

    // 2. OR user is admin and only changing non-critical fields
    $nonCritical = ['featured', 'pinned', 'highlighted'];
    $onlyNonCritical = empty(array_diff($changedFields, $nonCritical));
    $isAdmin = $user->hasRole('admin');

    if ($onlyMetadata || ($isAdmin && $onlyNonCritical)) {
        return false; // Skip workflow
    }

    return true; // Trigger workflow
});
```

### Wildcard Hooks

Apply hooks to multiple topics or actions:

```php
// Apply to all update actions across all models
Dynaflow::beforeTrigger('*', 'update', function ($workflow, $model, $data, $user) {
    // Your logic here
});

// Apply to all actions on Post model
Dynaflow::beforeTrigger(Post::class, '*', function ($workflow, $model, $data, $user) {
    // Your logic here
});

// Apply to everything
Dynaflow::beforeTrigger('*', '*', function ($workflow, $model, $data, $user) {
    // Global logic
});
```

### Hook Execution Order

Multiple hooks are executed in registration order. If **any** hook returns `false`, the workflow is skipped:

```php
// First hook
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    echo "First hook\n";
    return true; // Continue
});

// Second hook
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    echo "Second hook\n";
    return false; // Skip workflow - third hook won't run
});

// Third hook (won't execute if second returns false)
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    echo "Third hook\n";
    return true;
});
```

## Combining Both Approaches

You can use **both** field-based filtering and hooks together:

```php
// Configure workflow with monitored fields
$workflow = Dynaflow::create([
    'name' => ['en' => 'Post Update Approval'],
    'topic' => Post::class,
    'action' => 'update',
    'active' => true,
    'monitored_fields' => ['title', 'content', 'status'],
]);

// Add additional logic with a hook
Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
    // Even if a monitored field changed, skip if user is admin
    if ($user->hasRole('admin')) {
        return false;
    }

    return true;
});
```

**Execution order:**
1. Field-based filtering is checked first
2. If field filtering passes, `beforeTrigger` hooks run
3. If any hook returns `false`, workflow is skipped
4. Otherwise, workflow is triggered

## Best Practices

### Use Field Filtering When:
- ✅ You have simple, static rules (e.g., "ignore view_count updates")
- ✅ Rules don't depend on user context or complex logic
- ✅ You want configuration-based control

### Use beforeTrigger Hooks When:
- ✅ You need conditional logic (e.g., based on user role)
- ✅ Rules depend on the values being changed
- ✅ You need to combine multiple conditions
- ✅ You want dynamic, code-based control

### Performance Tips

1. **Prefer field filtering** for simple cases (faster, no callback overhead)
2. **Keep hooks lightweight** - they run on every workflow trigger
3. **Use early returns** in hooks to minimize processing
4. **Avoid database queries** in hooks when possible

## Complete Example

Here's a real-world example combining everything:

```php
// app/Providers/DynaflowServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Models\Dynaflow as DynaflowModel;
use App\Models\Post;

class DynaflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Configure workflow with ignored fields
        $workflow = DynaflowModel::updateOrCreate(
            ['topic' => Post::class, 'action' => 'update'],
            [
                'name' => ['en' => 'Post Update Approval'],
                'active' => true,
                'ignored_fields' => ['view_count', 'likes_count', 'shares_count'],
            ]
        );

        // 2. Add complex conditional logic
        Dynaflow::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
            // Admins can bypass for any single field change
            if ($user->hasRole('admin') && count($data) === 1) {
                return false;
            }

            // Auto-publish from draft doesn't need approval
            if (isset($data['status'])
                && $model->status === 'draft'
                && $data['status'] === 'published') {
                return false;
            }

            return true;
        });

        // 3. Register completion hook
        Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });
    }
}
```

## Troubleshooting

### Workflow Not Skipping as Expected

**Check the execution order:**
1. User exceptions (bypass)
2. Field-based filtering
3. beforeTrigger hooks

If user has an exception, field filtering and hooks are never checked.

### Hook Not Running

- Ensure hook is registered **before** trigger is called (typically in service provider)
- Check topic/action names match exactly
- Verify workflow exists and is active

### Changes Not Applied

When workflow is skipped, changes are applied via completion hooks. Ensure you have registered a completion hook for the action:

```php
Dynaflow::onComplete(Post::class, 'update', function ($instance, $user) {
    $instance->model->update($instance->dynaflowData->data);
});
```

## See Also

- [Hook Registration Guide](./HOOK_REGISTRATION.md)
- [Integration Guide](./INTEGRATION_GUIDE.md)
- [Quick Start](./QUICK_START.md)
