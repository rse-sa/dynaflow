<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\ExpressionEvaluator;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Action handler for conditional routing based on expressions.
 *
 * Evaluates a list of conditions and routes to the first matching step.
 * This is the simplest form of decision node using expression-based logic.
 *
 * Configuration example:
 * ```php
 * [
 *     'conditions' => [
 *         [
 *             'field' => 'model.amount',
 *             'operator' => '>',
 *             'value' => '10000',
 *             'route_to' => 'director_approval',
 *         ],
 *         [
 *             'field' => 'model.department',
 *             'operator' => 'in',
 *             'value' => ['finance', 'hr'],
 *             'route_to' => 'compliance_review',
 *         ],
 *     ],
 *     'default_route' => 'standard_approval',
 * ]
 * ```
 */
class ConditionalActionHandler implements ActionHandler
{
    public function __construct(
        protected ExpressionEvaluator $evaluator
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config     = $step->action_config ?? [];
        $conditions = $config['conditions'] ?? [];
        $default    = $config['default_route'] ?? null;

        // If no conditions defined, use first allowed transition
        if (empty($conditions)) {
            $firstTransition = $step->allowedTransitions()->first();
            if ($firstTransition) {
                return ActionResult::routeTo($firstTransition->key, [
                    'reason' => 'No conditions defined, using first available transition',
                ]);
            }

            return ActionResult::failed('No conditions defined and no default route available');
        }

        // Evaluate conditions
        $route = $this->evaluator->evaluateConditions($conditions, $ctx, $default);

        if ($route === null) {
            // Try to find default from allowed transitions
            if ($default === null) {
                $firstTransition = $step->allowedTransitions()->first();
                $route           = $firstTransition?->key;
            }

            if ($route === null) {
                return ActionResult::failed('No condition matched and no default route specified');
            }
        }

        // Find the matching condition for logging
        $matchedCondition = null;
        foreach ($conditions as $condition) {
            if ($this->evaluator->evaluate($condition, $ctx)) {
                $matchedCondition = $condition;
                break;
            }
        }

        return ActionResult::routeTo($route, [
            'matched_condition' => $matchedCondition,
            'conditions_count'  => count($conditions),
            'is_default'        => $matchedCondition === null,
        ]);
    }

    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'conditions' => [
                    'type'  => 'array',
                    'title' => 'Conditions',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'field' => [
                                'type'        => 'string',
                                'title'       => 'Field',
                                'description' => 'Field path to evaluate (e.g., model.amount)',
                            ],
                            'operator' => [
                                'type'        => 'string',
                                'title'       => 'Operator',
                                'enum'        => ['==', '!=', '>', '<', '>=', '<=', 'contains', 'in', 'not_in', 'empty', 'not_empty'],
                                'description' => 'Comparison operator',
                            ],
                            'value' => [
                                'title'       => 'Value',
                                'description' => 'Value to compare against',
                            ],
                            'route_to' => [
                                'type'        => 'string',
                                'title'       => 'Route To',
                                'description' => 'Step key to route to if condition matches',
                            ],
                        ],
                        'required' => ['field', 'operator', 'route_to'],
                    ],
                ],
                'default_route' => [
                    'type'        => 'string',
                    'title'       => 'Default Route',
                    'description' => 'Step to route to if no conditions match',
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Conditional';
    }

    public function getDescription(): string
    {
        return 'Route workflow based on conditions. Evaluates expressions and routes to the first matching step.';
    }

    public function getCategory(): string
    {
        return 'flow';
    }

    public function getIcon(): string
    {
        return 'git-branch';
    }

    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'matched_condition' => [
                    'type'        => 'object',
                    'description' => 'The condition that matched (null if default)',
                ],
                'is_default' => [
                    'type'        => 'boolean',
                    'description' => 'Whether the default route was used',
                ],
            ],
        ];
    }
}
