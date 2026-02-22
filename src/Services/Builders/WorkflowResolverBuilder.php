<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for workflow resolver callbacks.
 */
class WorkflowResolverBuilder
{
    public function __construct(
        protected DynaflowHookManager $manager,
        protected string $topic,
        protected string $action
    ) {}

    /**
     * Register the workflow resolver callback.
     *
     * Return a Dynaflow instance to use, or null to fall back to the default lookup.
     *
     * @param  Closure  $callback  Receives flexible params: $topic, $action, $model, $data, $user
     */
    public function execute(Closure $callback): void
    {
        $this->manager->pushWorkflowResolver($this->topic, $this->action, $callback);
    }
}
