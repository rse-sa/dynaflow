<?php

namespace RSE\DynaFlow\Services;

use Closure;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class CallbackInvoker
{
    /**
     * Invoke a callback with flexible parameter resolution.
     *
     * @param  Closure  $callback  The callback to invoke
     * @param  array  $available  Available parameters (associative array)
     * @return mixed The callback result
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException If a required parameter cannot be resolved
     */
    public function invoke(Closure $callback, array $available): mixed
    {
        $reflection = new ReflectionFunction($callback);
        $params     = $reflection->getParameters();
        $args       = [];

        foreach ($params as $index => $param) {
            $args[] = $this->resolveParameter($param, $available, $index);
        }

        return $callback(...$args);
    }

    /**
     * Resolve a single parameter value.
     *
     * Priority:
     * 1. Type hint match (instanceof check)
     * 2. Parameter name match
     * 3. Positional fallback (backwards compatibility)
     * 4. Default value
     * 5. Nullable (return null)
     * 6. Throw exception
     *
     * @param  ReflectionParameter  $param  The parameter to resolve
     * @param  array  $available  Available parameters
     * @param  int  $index  The parameter index (for positional fallback)
     * @return mixed The resolved value
     *
     * @throws InvalidArgumentException If parameter cannot be resolved
     */
    private function resolveParameter(ReflectionParameter $param, array $available, int $index): mixed
    {
        $name = $param->getName();
        $type = $param->getType();

        // 1. Type hint match (highest priority)
        $typeMatch = $this->resolveByType($type, $available);
        if ($typeMatch !== null) {
            return $typeMatch;
        }

        // 2. Parameter name match
        if (array_key_exists($name, $available)) {
            return $available[$name];
        }

        // 3. Positional fallback (backwards compat) - only if numeric keys exist
        if (array_key_exists($index, $available)) {
            return $available[$index];
        }

        // 4. Default value
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // 5. Nullable
        if ($param->allowsNull()) {
            return null;
        }

        // 6. Throw exception
        throw new InvalidArgumentException(
            "Cannot resolve parameter '\${$name}' for callback. " .
            "Available parameters: " . implode(', ', array_keys($available))
        );
    }

    /**
     * Resolve parameter by type hint.
     *
     * @param  \ReflectionType|null  $type  The parameter type
     * @param  array  $available  Available parameters
     * @return mixed|null The resolved value or null if not found
     */
    private function resolveByType($type, array $available): mixed
    {
        if ($type === null) {
            return null;
        }

        // ReflectionNamedType - single type hint
        if ($type instanceof ReflectionNamedType) {
            return $this->resolveByNamedType($type, $available);
        }

        // ReflectionUnionType - union types (e.g., string|int)
        if ($type instanceof ReflectionUnionType) {
            return $this->resolveByUnionType($type, $available);
        }

        // ReflectionIntersectionType - intersection types (e.g., A&B)
        if ($type instanceof ReflectionIntersectionType) {
            return $this->resolveByIntersectionType($type, $available);
        }

        return null;
    }

    /**
     * Resolve by single named type.
     *
     * @param  ReflectionNamedType  $type  The named type
     * @param  array  $available  Available parameters
     * @return mixed|null The resolved value or null if not found
     */
    private function resolveByNamedType(ReflectionNamedType $type, array $available): mixed
    {
        // Built-in types (int, string, array, etc.) - cannot match by instanceof
        if ($type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        // Find first value that matches the class type
        foreach ($available as $value) {
            if (is_object($value) && $value instanceof $className) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve by union type (e.g., string|int|Post).
     *
     * @param  ReflectionUnionType  $type  The union type
     * @param  array  $available  Available parameters
     * @return mixed|null The resolved value or null if not found
     */
    private function resolveByUnionType(ReflectionUnionType $type, array $available): mixed
    {
        // Try to match any of the union types
        foreach ($type->getTypes() as $unionType) {
            $result = $this->resolveByType($unionType, $available);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Resolve by intersection type (e.g., A&B).
     *
     * @param  ReflectionIntersectionType  $type  The intersection type
     * @param  array  $available  Available parameters
     * @return mixed|null The resolved value or null if not found
     */
    private function resolveByIntersectionType(ReflectionIntersectionType $type, array $available): mixed
    {
        // Find a value that matches ALL intersection types
        foreach ($available as $value) {
            if (! is_object($value)) {
                continue;
            }

            $matchesAll = true;
            foreach ($type->getTypes() as $intersectionType) {
                if ($intersectionType instanceof ReflectionNamedType) {
                    $className = $intersectionType->getName();
                    if (! is_a($value, $className, true)) {
                        $matchesAll = false;
                        break;
                    }
                }
            }

            if ($matchesAll) {
                return $value;
            }
        }

        return null;
    }
}
