<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for assignee resolvers.
 */
class AssigneeResolverBuilder
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
     * Register the assignee resolver.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        $this->manager->resolveAssigneesFor($this->topic, $this->action, $callback);
    }
}
