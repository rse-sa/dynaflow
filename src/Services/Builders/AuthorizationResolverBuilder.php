<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for authorization resolvers.
 */
class AuthorizationResolverBuilder
{
    protected DynaflowHookManager $manager;

    protected string $topic;

    protected string $action;

    public function __construct(
        DynaflowHookManager $manager,
        string $topic,
        string $action
    ) {
        $this->manager = $manager;
        $this->topic   = $topic;
        $this->action  = $action;
    }

    /**
     * Register the authorization resolver.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        $this->manager->authorizeStepFor($this->topic, $this->action, $callback);
    }
}
