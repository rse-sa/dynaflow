<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\ExpressionEvaluator;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\Support\DynaflowContext;
use Throwable;

/**
 * Decision Action Handler
 *
 * Routes workflow based on conditions. Supports three modes:
 * - expression: Uses condition expressions (like ConditionalActionHandler)
 * - script: Uses a developer-registered PHP script to decide
 * - ai: Uses an AI resolver to make the routing decision
 */
class DecisionActionHandler implements ActionHandler
{
    public function __construct(
        protected ExpressionEvaluator $evaluator,
        protected PlaceholderResolver $placeholders
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $mode = $config['mode'] ?? 'expression';

        return match ($mode) {
            'expression' => $this->executeExpression($config, $ctx),
            'script' => $this->executeScript($config, $ctx),
            'ai' => $this->executeAI($config, $ctx),
            default => ActionResult::failed("Unknown decision mode: $mode"),
        };
    }

    /**
     * Execute expression-based routing.
     */
    protected function executeExpression(array $config, DynaflowContext $ctx): ActionResult
    {
        $conditions = $config['conditions'] ?? [];
        $defaultRoute = $config['default_route'] ?? null;

        if (empty($conditions) && ! $defaultRoute) {
            return ActionResult::failed('No conditions or default route configured');
        }

        $targetRoute = $this->evaluator->evaluateConditions($conditions, $ctx, $defaultRoute);

        if (! $targetRoute) {
            return ActionResult::failed('No condition matched and no default route configured');
        }

        return ActionResult::routeTo($targetRoute, [
            'mode' => 'expression',
            'matched_route' => $targetRoute,
        ]);
    }

    /**
     * Execute script-based routing.
     */
    protected function executeScript(array $config, DynaflowContext $ctx): ActionResult
    {
        $scriptKey = $config['script'] ?? null;

        if (! $scriptKey) {
            return ActionResult::failed('No script specified for decision');
        }

        $script = Dynaflow::getScript($scriptKey);

        if (! $script) {
            return ActionResult::failed("Decision script '$scriptKey' not found. Register with Dynaflow::registerScript().");
        }

        $params = $config['params'] ?? [];
        $allowedRoutes = $config['allowed_routes'] ?? [];

        try {
            $result = $script($ctx, $params);

            // Script should return the step key to route to
            if (! is_string($result)) {
                return ActionResult::failed('Decision script must return a string (step key)');
            }

            // Validate against allowed routes if specified
            if (! empty($allowedRoutes) && ! in_array($result, $allowedRoutes, true)) {
                return ActionResult::failed("Script returned invalid route '$result'. Allowed: " . implode(', ', $allowedRoutes));
            }

            return ActionResult::routeTo($result, [
                'mode' => 'script',
                'script' => $scriptKey,
                'decision' => $result,
            ]);
        } catch (Throwable $e) {
            return ActionResult::failed("Decision script failed: {$e->getMessage()}", [
                'script' => $scriptKey,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Execute AI-based routing.
     */
    protected function executeAI(array $config, DynaflowContext $ctx): ActionResult
    {
        $provider = $config['provider'] ?? 'default';
        $prompt = $config['prompt'] ?? null;
        $allowedRoutes = $config['allowed_routes'] ?? [];

        if (! $prompt) {
            return ActionResult::failed('No prompt specified for AI decision');
        }

        if (empty($allowedRoutes)) {
            return ActionResult::failed('AI decision requires allowed_routes to constrain the AI response');
        }

        // Resolve placeholders in prompt
        $prompt = $this->placeholders->resolve($prompt, $ctx);

        $resolver = Dynaflow::getAIResolver($provider);

        if (! $resolver) {
            return ActionResult::failed("AI resolver '$provider' not found. Register with Dynaflow::registerAIResolver().");
        }

        try {
            // Prepare options for the AI resolver
            $options = [
                'model' => $config['model'] ?? null,
                'temperature' => $config['temperature'] ?? 0.1,
                'max_tokens' => $config['max_tokens'] ?? 100,
                'context' => $this->buildAIContext($ctx),
            ];

            // Call the AI resolver
            $decision = is_callable($resolver)
                ? $resolver($prompt, $allowedRoutes, $options)
                : $resolver->resolve($prompt, $allowedRoutes, $options);

            // Validate the AI's decision
            if (! in_array($decision, $allowedRoutes, true)) {
                // AI returned invalid route, use fallback if configured
                $fallback = $config['fallback_route'] ?? null;
                if ($fallback) {
                    return ActionResult::routeTo($fallback, [
                        'mode' => 'ai',
                        'provider' => $provider,
                        'ai_decision' => $decision,
                        'used_fallback' => true,
                    ]);
                }

                return ActionResult::failed("AI returned invalid route '$decision'. Allowed: " . implode(', ', $allowedRoutes));
            }

            return ActionResult::routeTo($decision, [
                'mode' => 'ai',
                'provider' => $provider,
                'decision' => $decision,
            ]);
        } catch (Throwable $e) {
            // On AI failure, use fallback if configured
            $fallback = $config['fallback_route'] ?? null;
            if ($fallback) {
                return ActionResult::routeTo($fallback, [
                    'mode' => 'ai',
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                    'used_fallback' => true,
                ]);
            }

            return ActionResult::failed("AI decision failed: {$e->getMessage()}", [
                'provider' => $provider,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Build context information for the AI.
     */
    protected function buildAIContext(DynaflowContext $ctx): array
    {
        return [
            'workflow' => [
                'topic' => $ctx->topic(),
                'action' => $ctx->action(),
            ],
            'instance' => [
                'id' => $ctx->instance->id,
                'status' => $ctx->instance->status,
            ],
            'model' => $ctx->model()?->toArray() ?? [],
            'pending_data' => $ctx->pendingData(),
            'user' => [
                'id' => $ctx->user?->getKey(),
            ],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['mode'],
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'title' => 'Decision Mode',
                    'description' => 'How to make the routing decision.',
                    'enum' => ['expression', 'script', 'ai'],
                    'default' => 'expression',
                ],
                // Expression mode properties
                'conditions' => [
                    'type' => 'array',
                    'title' => 'Conditions (Expression Mode)',
                    'description' => 'Array of conditions with route targets.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => ['type' => 'string'],
                            'value' => ['type' => 'mixed'],
                            'route' => ['type' => 'string'],
                        ],
                    ],
                ],
                'default_route' => [
                    'type' => 'string',
                    'title' => 'Default Route',
                    'description' => 'Route to use if no conditions match.',
                ],
                // Script mode properties
                'script' => [
                    'type' => 'string',
                    'title' => 'Script Key (Script Mode)',
                    'description' => 'The registered script to use for decision.',
                ],
                'params' => [
                    'type' => 'object',
                    'title' => 'Script Parameters',
                    'description' => 'Parameters to pass to the script.',
                ],
                // AI mode properties
                'provider' => [
                    'type' => 'string',
                    'title' => 'AI Provider (AI Mode)',
                    'description' => 'The registered AI resolver to use.',
                    'default' => 'default',
                ],
                'prompt' => [
                    'type' => 'string',
                    'title' => 'AI Prompt',
                    'description' => 'The prompt to send to the AI. Supports placeholders.',
                ],
                'allowed_routes' => [
                    'type' => 'array',
                    'title' => 'Allowed Routes',
                    'description' => 'Valid routes the AI can choose from.',
                    'items' => ['type' => 'string'],
                ],
                'model' => [
                    'type' => 'string',
                    'title' => 'AI Model',
                    'description' => 'Specific AI model to use (provider-dependent).',
                ],
                'fallback_route' => [
                    'type' => 'string',
                    'title' => 'Fallback Route',
                    'description' => 'Route to use if AI fails or returns invalid response.',
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Decision';
    }

    public function getDescription(): string
    {
        return 'Route workflow based on conditions, script, or AI';
    }

    public function getCategory(): string
    {
        return 'Flow Control';
    }

    public function getIcon(): string
    {
        return 'git-compare';
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'description' => 'The decision mode used'],
                'decision' => ['type' => 'string', 'description' => 'The routing decision made'],
                'matched_route' => ['type' => 'string', 'description' => 'The route that was matched'],
            ],
        ];
    }
}
