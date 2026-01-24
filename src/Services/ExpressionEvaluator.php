<?php

namespace RSE\DynaFlow\Services;

use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Evaluates conditions for conditional routing.
 *
 * Supports basic comparison operators for field-based routing decisions.
 * Used by ConditionalActionHandler and DecisionActionHandler.
 *
 * Condition format:
 * ```php
 * [
 *     'field' => 'model.amount',      // Field path to evaluate
 *     'operator' => '>',               // Comparison operator
 *     'value' => '10000',              // Value to compare against
 *     'route_to' => 'director_approval' // Target step if condition matches
 * ]
 * ```
 *
 * Supported operators:
 * - Equality: ==, ===, !=, !==
 * - Comparison: >, <, >=, <=
 * - String: contains, not_contains, starts_with, ends_with
 * - Array: in, not_in
 * - Null/Empty: empty, not_empty, null, not_null
 */
class ExpressionEvaluator
{
    public function __construct(
        protected PlaceholderResolver $resolver
    ) {}

    /**
     * Evaluate a single condition.
     *
     * @param  array  $condition  The condition to evaluate
     * @param  DynaflowContext  $ctx  The workflow context
     * @return bool True if condition matches
     */
    public function evaluate(array $condition, DynaflowContext $ctx): bool
    {
        $field       = $condition['field'] ?? '';
        $operator    = $condition['operator'] ?? '==';
        $compareValue = $condition['value'] ?? null;

        // Resolve field value from context using placeholder syntax
        $actualValue = $this->resolver->resolve('{{' . $field . '}}', $ctx);

        // Also resolve compare value in case it has placeholders
        if (is_string($compareValue) && $this->resolver->hasPlaceholders($compareValue)) {
            $compareValue = $this->resolver->resolve($compareValue, $ctx);
        }

        return $this->compare($actualValue, $operator, $compareValue);
    }

    /**
     * Evaluate multiple conditions and return the first matching route.
     *
     * @param  array  $conditions  Array of conditions with 'route_to' keys
     * @param  DynaflowContext  $ctx  The workflow context
     * @param  string|null  $defaultRoute  Default route if no conditions match
     * @return string|null The route to take
     */
    public function evaluateConditions(array $conditions, DynaflowContext $ctx, ?string $defaultRoute = null): ?string
    {
        foreach ($conditions as $condition) {
            if ($this->evaluate($condition, $ctx)) {
                return $condition['route_to'] ?? $defaultRoute;
            }
        }

        return $defaultRoute;
    }

    /**
     * Compare two values using the specified operator.
     *
     * @param  mixed  $actual  The actual value
     * @param  string  $operator  The comparison operator
     * @param  mixed  $expected  The expected value
     * @return bool Result of comparison
     */
    protected function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        // Convert numeric strings for comparison operators
        if (in_array($operator, ['>', '<', '>=', '<='])) {
            $actual   = is_numeric($actual) ? (float) $actual : $actual;
            $expected = is_numeric($expected) ? (float) $expected : $expected;
        }

        return match ($operator) {
            // Equality
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '!==' => $actual !== $expected,

            // Comparison
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,

            // String operations
            'contains' => is_string($actual) && str_contains($actual, (string) $expected),
            'not_contains' => is_string($actual) && ! str_contains($actual, (string) $expected),
            'starts_with' => is_string($actual) && str_starts_with($actual, (string) $expected),
            'ends_with' => is_string($actual) && str_ends_with($actual, (string) $expected),
            'matches' => is_string($actual) && preg_match((string) $expected, $actual),

            // Array operations
            'in' => in_array($actual, (array) $expected, false),
            'not_in' => ! in_array($actual, (array) $expected, false),

            // Null/Empty checks
            'empty' => empty($actual),
            'not_empty' => ! empty($actual),
            'null' => $actual === null || $actual === 'null' || $actual === '',
            'not_null' => $actual !== null && $actual !== 'null' && $actual !== '',

            // Type checks
            'is_numeric' => is_numeric($actual),
            'is_string' => is_string($actual),
            'is_array' => is_array($actual),
            'is_bool' => is_bool($actual) || in_array(strtolower((string) $actual), ['true', 'false'], true),

            // Default
            default => $actual == $expected,
        };
    }

    /**
     * Get all supported operators with descriptions.
     *
     * @return array Operators with metadata for UI
     */
    public static function getSupportedOperators(): array
    {
        return [
            [
                'value'       => '==',
                'label'       => 'Equals',
                'description' => 'Values are equal (loose comparison)',
            ],
            [
                'value'       => '===',
                'label'       => 'Strictly equals',
                'description' => 'Values are equal (strict type comparison)',
            ],
            [
                'value'       => '!=',
                'label'       => 'Not equals',
                'description' => 'Values are not equal',
            ],
            [
                'value'       => '>',
                'label'       => 'Greater than',
                'description' => 'Value is greater than',
            ],
            [
                'value'       => '<',
                'label'       => 'Less than',
                'description' => 'Value is less than',
            ],
            [
                'value'       => '>=',
                'label'       => 'Greater than or equal',
                'description' => 'Value is greater than or equal to',
            ],
            [
                'value'       => '<=',
                'label'       => 'Less than or equal',
                'description' => 'Value is less than or equal to',
            ],
            [
                'value'       => 'contains',
                'label'       => 'Contains',
                'description' => 'String contains the value',
            ],
            [
                'value'       => 'not_contains',
                'label'       => 'Does not contain',
                'description' => 'String does not contain the value',
            ],
            [
                'value'       => 'starts_with',
                'label'       => 'Starts with',
                'description' => 'String starts with the value',
            ],
            [
                'value'       => 'ends_with',
                'label'       => 'Ends with',
                'description' => 'String ends with the value',
            ],
            [
                'value'       => 'in',
                'label'       => 'In list',
                'description' => 'Value is in the provided list',
            ],
            [
                'value'       => 'not_in',
                'label'       => 'Not in list',
                'description' => 'Value is not in the provided list',
            ],
            [
                'value'       => 'empty',
                'label'       => 'Is empty',
                'description' => 'Value is empty or null',
            ],
            [
                'value'       => 'not_empty',
                'label'       => 'Is not empty',
                'description' => 'Value is not empty',
            ],
            [
                'value'       => 'null',
                'label'       => 'Is null',
                'description' => 'Value is null or empty string',
            ],
            [
                'value'       => 'not_null',
                'label'       => 'Is not null',
                'description' => 'Value exists and is not null',
            ],
        ];
    }
}
