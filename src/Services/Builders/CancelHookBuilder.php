<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for cancellation hooks.
 */
class CancelHookBuilder
{
    protected DynaflowHookManager $manager;

    protected string|array $topic;

    protected string|array $action;

    public function __construct(
        DynaflowHookManager $manager,
        string|array $topic,
        string|array $action
    ) {
        $this->manager = $manager;
        $this->topic   = $topic;
        $this->action  = $action;
    }

    /**
     * Override topic.
     *
     * @return $this
     */
    public function forTopic(string|array $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    /**
     * Override action.
     *
     * @return $this
     */
    public function forAction(string|array $action): static
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Register the cancellation hook.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        $this->manager->onCancel($this->topic, $this->action, $callback);
    }
}
