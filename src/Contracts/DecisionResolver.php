<?php

namespace RSE\DynaFlow\Contracts;

/**
 * Interface for AI decision resolvers.
 *
 * Implement this interface to create custom AI-powered routing resolvers
 * for use with the DecisionActionHandler in AI mode.
 *
 * Register your resolver with:
 * Dynaflow::registerAIResolver('my-provider', MyResolver::class);
 */
interface DecisionResolver
{
    /**
     * Resolve a routing decision using AI.
     *
     * @param  string  $prompt  The decision prompt with context
     * @param  array<string>  $allowedRoutes  Valid route options the AI can choose from
     * @param  array  $options  Additional options (model, temperature, max_tokens, context)
     * @return string The chosen route (must be one of $allowedRoutes)
     *
     * @throws \RuntimeException If the AI fails to return a valid route
     */
    public function resolve(string $prompt, array $allowedRoutes, array $options = []): string;
}
