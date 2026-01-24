<?php

namespace RSE\DynaFlow\Services;

use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Resolves placeholder syntax in strings.
 *
 * Placeholders use the {{namespace.path}} syntax and are resolved
 * from various sources in the workflow context.
 *
 * Available placeholders:
 * - {{model.*}}           - Model attributes (e.g., {{model.title}}, {{model.owner.name}})
 * - {{context.*}}         - Workflow context data stored via Set Variable
 * - {{instance.*}}        - DynaflowInstance attributes
 * - {{instance.data.*}}   - Pending data from DynaflowData
 * - {{step.*}}            - Current/target step attributes
 * - {{user.*}}            - Executing user attributes
 * - {{previous.*}}        - Previous step execution data
 * - {{previous.decision}} - Last decision made
 * - {{env.*}}             - Environment variables
 * - {{config:*}}          - Laravel config values
 * - {{date:format}}       - Current date (e.g., {{date:Y-m-d H:i}})
 *
 * Example:
 * ```php
 * $resolver = app(PlaceholderResolver::class);
 * $subject = $resolver->resolve('Order #{{model.id}} by {{user.name}}', $ctx);
 * ```
 */
class PlaceholderResolver
{
    /**
     * Resolve all placeholders in a template string.
     *
     * @param  string|null  $template  The template with placeholders
     * @param  DynaflowContext  $ctx  The workflow context
     * @return string The resolved string
     */
    public function resolve(?string $template, DynaflowContext $ctx): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        return preg_replace_callback('/\{\{(.+?)\}\}/', function ($matches) use ($ctx) {
            $placeholder = trim($matches[1]);

            return $this->resolvePlaceholder($placeholder, $ctx) ?? $matches[0];
        }, $template);
    }

    /**
     * Resolve placeholders in an array recursively.
     *
     * @param  array  $data  The array with potential placeholders
     * @param  DynaflowContext  $ctx  The workflow context
     * @return array The resolved array
     */
    public function resolveArray(array $data, DynaflowContext $ctx): array
    {
        $resolved = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->resolve($value, $ctx);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->resolveArray($value, $ctx);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Resolve a single placeholder.
     *
     * @param  string  $placeholder  The placeholder without braces
     * @param  DynaflowContext  $ctx  The workflow context
     * @return string|null The resolved value or null if not found
     */
    protected function resolvePlaceholder(string $placeholder, DynaflowContext $ctx): ?string
    {
        // Handle special format placeholders
        if (str_starts_with($placeholder, 'date:')) {
            $format = substr($placeholder, 5) ?: 'Y-m-d H:i:s';

            return now()->format($format);
        }

        if (str_starts_with($placeholder, 'config:')) {
            $key = substr($placeholder, 7);

            return (string) config($key, '');
        }

        if (str_starts_with($placeholder, 'env:')) {
            $key = substr($placeholder, 4);

            return (string) env($key, '');
        }

        // Parse namespace.path format
        $parts     = explode('.', $placeholder, 2);
        $namespace = $parts[0];
        $path      = $parts[1] ?? null;

        $value = $this->resolveNamespace($namespace, $path, $ctx);

        return $this->formatValue($value);
    }

    /**
     * Resolve a value from a specific namespace.
     */
    protected function resolveNamespace(string $namespace, ?string $path, DynaflowContext $ctx): mixed
    {
        $source = match ($namespace) {
            'model'    => $ctx->model(),
            'context'  => $ctx->get('variables', []),
            'instance' => $ctx->instance,
            'step'     => $ctx->targetStep,
            'user'     => $ctx->user,
            'previous' => $ctx->execution,
            'data'     => $ctx->pendingData(),
            'workflow' => $ctx->instance?->dynaflow,
            default    => null,
        };

        if ($source === null) {
            return null;
        }

        if ($path === null) {
            return $source;
        }

        return data_get($source, $path);
    }

    /**
     * Format a resolved value as a string.
     */
    protected function formatValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Extract all placeholder names from a template.
     *
     * @param  string  $template  The template to scan
     * @return array List of placeholder names (without braces)
     */
    public function extractPlaceholders(string $template): array
    {
        preg_match_all('/\{\{(.+?)\}\}/', $template, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Check if a string contains placeholders.
     *
     * @param  string  $template  The string to check
     */
    public function hasPlaceholders(string $template): bool
    {
        return str_contains($template, '{{') && str_contains($template, '}}');
    }
}
