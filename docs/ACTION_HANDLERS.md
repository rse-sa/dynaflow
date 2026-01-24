# Step Types & Action Handlers

Dynaflow supports two categories of steps: **stateful** (require human interaction) and **stateless** (auto-execute).

## Step Types

### Stateful Steps

Require human approval or input before continuing. The workflow pauses until someone executes `transitionTo()`.

| Type | Description |
|------|-------------|
| `approval` | Standard approval step (default) |
| `form` | Form submission step |
| `review` | Review step |
| `multi_choice` | Multiple choice decision |

### Stateless Steps

Execute automatically without human interaction. When the workflow reaches a stateless step, it immediately runs the configured action handler.

| Type | Description |
|------|-------------|
| `action` | Execute a registered action handler |
| `notification` | Send notifications |
| `http` | Make HTTP requests |
| `script` | Execute registered PHP scripts |
| `decision` | Route based on conditions/script/AI |
| `timer` / `delay` | Wait for duration |
| `parallel` | Fork into parallel branches |
| `join` | Wait for parallel branches |
| `sub_workflow` | Trigger child workflows |
| `conditional` | Conditional routing |

## Creating Steps

```php
use RSE\DynaFlow\Models\DynaflowStep;

// Stateful step (default)
$approvalStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_review',
    'name' => ['en' => 'Manager Review'],
    'type' => 'approval',  // Optional - 'approval' is default
    'order' => 1,
]);

// Stateless step with action handler
$notifyStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'notify_user',
    'name' => ['en' => 'Notify User'],
    'type' => 'notification',
    'action_handler' => 'email',
    'action_config' => [
        'to' => '{{model.user.email}}',
        'subject' => 'Your request was processed',
        'body' => 'Hello {{model.user.name}}, your request has been {{context.decision}}.',
    ],
    'order' => 2,
    'is_final' => true,
]);
```

## Auto-Execution Flow

When a stateless step becomes active:

1. `onStepActivated` hooks run
2. `AutoStepExecutor` resolves the action handler
3. Handler executes and returns `ActionResult`
4. Based on result:
   - **success** → Transition to next step
   - **failed** → Log error, stop execution
   - **waiting** → Schedule job to resume later (delays)
   - **forked** → Spawn parallel branches
   - **route_to** → Transition to specific step

If the next step is also stateless, execution continues automatically until a stateful step is reached.

---

## Built-in Action Handlers

### Email Handler

Send emails with full placeholder support.

```php
'action_handler' => 'email',
'action_config' => [
    'to' => '{{model.user.email}}',
    'subject' => 'Workflow Update: {{workflow.name}}',
    'body' => 'Hello {{model.user.name}},\n\nYour request "{{data.title}}" has been {{context.decision}}.\n\nRegards,\n{{config:app.name}}',
    'cc' => ['manager@example.com'],
    'bcc' => ['audit@example.com'],
    'from_name' => '{{config:app.name}}',
    'from_email' => 'noreply@example.com',
    'reply_to' => '{{user.email}}',
]
```

**All Fields:**
- `to` (required) - Recipient email
- `subject` (required) - Email subject
- `body` (required) - Email body (plain text or HTML)
- `cc` - Array of CC recipients
- `bcc` - Array of BCC recipients
- `from_name` - Sender name
- `from_email` - Sender email
- `reply_to` - Reply-to address

### HTTP Handler

Make HTTP requests to external services with retry support.

```php
'action_handler' => 'http',
'action_config' => [
    'url' => 'https://api.example.com/webhooks/workflow',
    'method' => 'POST',
    'headers' => [
        'Authorization' => 'Bearer {{config:services.api.token}}',
        'Content-Type' => 'application/json',
    ],
    'body' => [
        'event' => 'workflow_step_completed',
        'instance_id' => '{{instance.id}}',
        'model_type' => '{{instance.model_type}}',
        'model_id' => '{{instance.model_id}}',
        'decision' => '{{context.decision}}',
        'user_id' => '{{user.id}}',
    ],
    'timeout' => 30,
    'retry' => [
        'times' => 3,
        'delay' => 1000,  // milliseconds
    ],
]
```

**All Fields:**
- `url` (required) - Request URL
- `method` - HTTP method (GET, POST, PUT, PATCH, DELETE). Default: POST
- `headers` - Request headers
- `body` - Request body (for POST/PUT/PATCH)
- `query` - Query parameters (for GET)
- `timeout` - Timeout in seconds. Default: 30
- `retry.times` - Number of retries. Default: 0
- `retry.delay` - Delay between retries in ms. Default: 1000
- `auth.type` - Authentication type (basic, bearer)
- `auth.credentials` - Auth credentials

**Response Handling:**

The handler stores the response in `ActionResult.data`:

```php
// Access in subsequent steps via {{previous.response.*}}
'body' => 'External ID: {{previous.response.id}}'
```

### Delay Handler

Pause workflow execution for a specified duration.

```php
// Wait for a duration
'action_handler' => 'delay',
'action_config' => [
    'duration' => '2 hours',  // Supports: minutes, hours, days
]

// Wait until specific time
'action_handler' => 'delay',
'action_config' => [
    'until' => '{{model.review_at}}',  // ISO 8601 or Carbon-parseable
]

// Wait until tomorrow 9 AM
'action_handler' => 'delay',
'action_config' => [
    'until' => 'tomorrow 09:00',
]
```

**Fields:**
- `duration` - Human-readable duration ("30 minutes", "2 hours", "1 day")
- `until` - Specific datetime to resume

### Conditional Handler

Route workflow based on expression conditions.

```php
'action_handler' => 'conditional',
'action_config' => [
    'conditions' => [
        [
            'field' => 'model.amount',
            'operator' => '>',
            'value' => 10000,
            'route' => 'director_approval',
        ],
        [
            'field' => 'model.amount',
            'operator' => '>',
            'value' => 1000,
            'route' => 'manager_approval',
        ],
        [
            'field' => 'model.is_urgent',
            'operator' => '==',
            'value' => true,
            'route' => 'fast_track',
        ],
    ],
    'default_route' => 'auto_approved',
]
```

**Available Operators:**
- `==`, `!=` - Equality
- `>`, `<`, `>=`, `<=` - Comparison
- `contains` - String contains
- `starts_with`, `ends_with` - String prefix/suffix
- `in`, `not_in` - Array membership
- `empty`, `not_empty` - Null/empty check
- `matches` - Regex match

**Complex Conditions (AND/OR):**

```php
'conditions' => [
    [
        'logic' => 'and',
        'conditions' => [
            ['field' => 'model.amount', 'operator' => '>', 'value' => 10000],
            ['field' => 'model.department', 'operator' => '==', 'value' => 'finance'],
        ],
        'route' => 'cfo_approval',
    ],
    [
        'logic' => 'or',
        'conditions' => [
            ['field' => 'model.is_urgent', 'operator' => '==', 'value' => true],
            ['field' => 'model.priority', 'operator' => '==', 'value' => 'high'],
        ],
        'route' => 'fast_track',
    ],
]
```

### Parallel Handler (Fork)

Spawn multiple parallel execution branches.

```php
'action_handler' => 'parallel',
'action_config' => [
    'branches' => ['legal_review', 'finance_review', 'compliance_check'],
    'wait_for_all' => true,  // Wait for all branches at join
]
```

**Flow:**
1. Creates parallel execution group with unique ID
2. Dispatches jobs for each branch step
3. Each branch executes independently
4. All branches converge at a join step

### Join Handler

Synchronize parallel branches before continuing.

```php
'action_handler' => 'join',
'action_config' => [
    'group_id' => null,  // Auto-detect from instance metadata
    'wait_for_all' => true,  // false = continue when first completes
    'timeout_minutes' => 60,
]
```

**Output:**
```php
[
    'group_id' => 'parallel_abc123',
    'merged_results' => [
        'legal_review' => ['approved' => true],
        'finance_review' => ['approved' => true, 'budget_code' => 'A1'],
        'compliance_check' => ['passed' => true],
    ],
    'branch_count' => 3,
]
```

### Script Handler

Execute developer-registered PHP scripts.

**Register Script:**
```php
// In AppServiceProvider
use RSE\DynaFlow\Facades\Dynaflow;

Dynaflow::registerScript('calculate-priority', function (DynaflowContext $ctx, array $params) {
    $amount = $ctx->model()->amount ?? 0;
    $department = $ctx->model()->department;

    if ($amount > 100000) {
        return 'route:executive_approval';
    }

    if ($department === 'finance' && $amount > 10000) {
        return 'route:cfo_approval';
    }

    return 'route:manager_approval';
});

Dynaflow::registerScript('sync-to-erp', function (DynaflowContext $ctx, array $params) {
    $response = Http::post($params['endpoint'], [
        'model_id' => $ctx->model()->id,
        'data' => $ctx->pendingData(),
    ]);

    return $response->successful();
});
```

**Use in Step:**
```php
'action_handler' => 'script',
'action_config' => [
    'script' => 'calculate-priority',
    'params' => [
        'threshold' => 5000,
    ],
    'timeout_seconds' => 30,
]
```

**Return Values:**
- `'route:step_key'` - Route to specific step
- `true` - Success, continue to default next step
- `false` - Failure
- `ActionResult` instance - Full control over result

### Sub-Workflow Handler

Trigger a child workflow.

```php
'action_handler' => 'sub_workflow',
'action_config' => [
    'topic' => 'App\\Models\\Invoice',
    'action' => 'create',
    'data' => [
        'order_id' => '{{model.id}}',
        'amount' => '{{model.total}}',
        'customer_id' => '{{model.customer_id}}',
    ],
    'wait_for_completion' => false,  // true = pause until child completes
]
```

**Use Cases:**
- Approval chains that trigger billing workflows
- Document workflows that spawn notification sub-flows
- Multi-department approvals

### Decision Handler

Multi-mode routing: expression, script, or AI.

**Expression Mode:**
```php
'action_handler' => 'decision',
'action_config' => [
    'mode' => 'expression',
    'conditions' => [
        ['field' => 'model.type', 'operator' => '==', 'value' => 'legal', 'route' => 'legal_team'],
        ['field' => 'model.type', 'operator' => '==', 'value' => 'finance', 'route' => 'finance_team'],
    ],
    'default_route' => 'general_review',
]
```

**Script Mode:**
```php
'action_handler' => 'decision',
'action_config' => [
    'mode' => 'script',
    'script' => 'route-by-complexity',
    'params' => ['threshold' => 10],
    'allowed_routes' => ['simple_approval', 'complex_approval', 'expert_review'],
]
```

**AI Mode:**
```php
'action_handler' => 'decision',
'action_config' => [
    'mode' => 'ai',
    'provider' => 'openai',
    'prompt' => 'Review this {{workflow.action}} request for "{{model.title}}". Consider the content: {{model.content}}. Route to the most appropriate team.',
    'allowed_routes' => ['legal', 'finance', 'operations', 'hr'],
    'model' => 'gpt-4',
    'temperature' => 0.1,
    'fallback_route' => 'manual_review',
]
```

---

## Placeholder System

All action configs support `{{placeholder}}` syntax.

### Available Placeholders

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `{{model.*}}` | Current model attributes | `{{model.title}}`, `{{model.user.name}}` |
| `{{user.*}}` | User who triggered/executed | `{{user.email}}`, `{{user.id}}` |
| `{{data.*}}` | Pending workflow data | `{{data.title}}`, `{{data.amount}}` |
| `{{instance.*}}` | Workflow instance | `{{instance.id}}`, `{{instance.status}}` |
| `{{workflow.*}}` | Workflow definition | `{{workflow.name}}`, `{{workflow.action}}` |
| `{{step.*}}` | Current step | `{{step.name}}`, `{{step.key}}` |
| `{{context.*}}` | Execution context | `{{context.decision}}`, `{{context.notes}}` |
| `{{previous.*}}` | Previous step result | `{{previous.response.id}}` |
| `{{date:format}}` | Current date | `{{date:Y-m-d}}`, `{{date:H:i:s}}` |
| `{{config:key}}` | Config values | `{{config:app.name}}` |
| `{{env:KEY}}` | Environment variables | `{{env:APP_URL}}` |

### Nested Access

Access nested properties using dot notation:

```php
'to' => '{{model.department.manager.email}}'
'body' => 'Requested by {{model.user.profile.full_name}}'
```

### Default Values

Placeholders return empty string if value is null. Handle in your templates:

```php
'body' => 'Notes: {{context.notes}}' // Returns "Notes: " if no notes
```

---

## Registering Custom Handlers

### Class-Based Handler

```php
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;

class SlackNotificationHandler implements ActionHandler
{
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $resolver = app(PlaceholderResolver::class);

        $channel = $resolver->resolve($config['channel'] ?? '#general', $ctx);
        $message = $resolver->resolve($config['message'], $ctx);

        try {
            Http::post(config('services.slack.webhook'), [
                'channel' => $channel,
                'text' => $message,
            ]);

            return ActionResult::success(['channel' => $channel]);
        } catch (\Exception $e) {
            return ActionResult::failed($e->getMessage());
        }
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['message'],
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'title' => 'Channel',
                    'default' => '#general',
                ],
                'message' => [
                    'type' => 'string',
                    'title' => 'Message',
                    'description' => 'Supports placeholders',
                ],
            ],
        ];
    }

    public function getLabel(): string { return 'Slack Notification'; }
    public function getDescription(): string { return 'Send message to Slack channel'; }
    public function getCategory(): string { return 'Communication'; }
    public function getIcon(): string { return 'slack'; }
    public function getOutputSchema(): array { return ['type' => 'object']; }
}

// Register
Dynaflow::registerAction('slack', SlackNotificationHandler::class);
```

### Closure-Based Handler

```php
Dynaflow::registerAction('log-audit', function (DynaflowStep $step, DynaflowContext $ctx) {
    $config = $step->getActionConfig();

    Log::channel('audit')->info($config['message'], [
        'instance_id' => $ctx->instance->id,
        'step' => $ctx->targetStep->key,
        'user' => $ctx->user?->id,
        'model' => $ctx->model()?->toArray(),
    ]);

    return ActionResult::success();
});
```

---

## Registering AI Resolvers

For AI-powered decision routing.

### Interface Implementation

```php
use RSE\DynaFlow\Contracts\DecisionResolver;

class OpenAIResolver implements DecisionResolver
{
    public function resolve(string $prompt, array $allowedRoutes, array $options = []): string
    {
        $systemPrompt = "You are a workflow routing assistant. Based on the context provided, choose exactly one of these routes: " . implode(', ', $allowedRoutes) . ". Respond with only the route name, nothing else.";

        $response = OpenAI::chat()->create([
            'model' => $options['model'] ?? 'gpt-4',
            'temperature' => $options['temperature'] ?? 0.1,
            'max_tokens' => $options['max_tokens'] ?? 50,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $decision = trim($response->choices[0]->message->content);

        if (!in_array($decision, $allowedRoutes)) {
            throw new \RuntimeException("AI returned invalid route: $decision");
        }

        return $decision;
    }
}

// Register
Dynaflow::registerAIResolver('openai', OpenAIResolver::class);
```

### Closure-Based Resolver

```php
Dynaflow::registerAIResolver('anthropic', function (string $prompt, array $routes, array $options) {
    $response = Http::withHeaders([
        'x-api-key' => config('services.anthropic.key'),
    ])->post('https://api.anthropic.com/v1/messages', [
        'model' => $options['model'] ?? 'claude-3-sonnet-20240229',
        'max_tokens' => 50,
        'messages' => [
            ['role' => 'user', 'content' => "Choose one of: " . implode(', ', $routes) . "\n\n" . $prompt],
        ],
    ]);

    return trim($response->json('content.0.text'));
});
```

---

## Example: Complete Workflow with Mixed Steps

```php
// Create workflow
$workflow = Dynaflow::create([
    'name' => ['en' => 'Purchase Request'],
    'topic' => PurchaseRequest::class,
    'action' => 'create',
    'active' => true,
]);

// Step 1: Conditional routing based on amount
$routeStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'route_by_amount',
    'name' => ['en' => 'Route by Amount'],
    'type' => 'conditional',
    'action_handler' => 'conditional',
    'action_config' => [
        'conditions' => [
            ['field' => 'data.amount', 'operator' => '>', 'value' => 10000, 'route' => 'director_approval'],
            ['field' => 'data.amount', 'operator' => '>', 'value' => 1000, 'route' => 'manager_approval'],
        ],
        'default_route' => 'auto_approve',
    ],
    'order' => 1,
]);

// Step 2a: Manager approval (stateful)
$managerStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'manager_approval',
    'name' => ['en' => 'Manager Approval'],
    'type' => 'approval',
    'order' => 2,
]);

// Step 2b: Director approval (stateful)
$directorStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'director_approval',
    'name' => ['en' => 'Director Approval'],
    'type' => 'approval',
    'order' => 2,
]);

// Step 2c: Auto-approve (stateless - goes directly to notify)
$autoApproveStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'auto_approve',
    'name' => ['en' => 'Auto Approve'],
    'type' => 'action',
    'action_handler' => 'script',
    'action_config' => [
        'script' => 'log-auto-approval',
    ],
    'order' => 2,
]);

// Step 3: Send notification (stateless)
$notifyStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'notify_requester',
    'name' => ['en' => 'Notify Requester'],
    'type' => 'notification',
    'action_handler' => 'email',
    'action_config' => [
        'to' => '{{model.requester.email}}',
        'subject' => 'Purchase Request {{context.decision}}',
        'body' => 'Your purchase request for ${{data.amount}} has been {{context.decision}}.',
    ],
    'order' => 3,
]);

// Step 4: Sync to ERP (stateless)
$syncStep = DynaflowStep::create([
    'dynaflow_id' => $workflow->id,
    'key' => 'sync_erp',
    'name' => ['en' => 'Sync to ERP'],
    'type' => 'http',
    'action_handler' => 'http',
    'action_config' => [
        'url' => '{{config:services.erp.url}}/purchase-requests',
        'method' => 'POST',
        'headers' => ['Authorization' => 'Bearer {{config:services.erp.token}}'],
        'body' => [
            'external_id' => '{{instance.id}}',
            'amount' => '{{data.amount}}',
            'vendor' => '{{data.vendor}}',
        ],
    ],
    'order' => 4,
    'is_final' => true,
]);

// Define transitions
$routeStep->allowedTransitions()->attach([$managerStep->id, $directorStep->id, $autoApproveStep->id]);
$managerStep->allowedTransitions()->attach($notifyStep->id);
$directorStep->allowedTransitions()->attach($notifyStep->id);
$autoApproveStep->allowedTransitions()->attach($notifyStep->id);
$notifyStep->allowedTransitions()->attach($syncStep->id);
```

---

## Next Steps

- [Hooks](HOOKS.md) - Hook registration and patterns
- [Extras](EXTRAS.md) - Bypass modes, field filtering, drafts
- [Integration](INTEGRATION.md) - Controller integration
