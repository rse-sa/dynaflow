<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for step activated hooks.
 */
class StepActivatedHookBuilder
{
    protected DynaflowHookManager $manager;

    protected string|array $stepIdentifier;

    protected ?string $topic;

    protected ?string $action;

    public function __construct(
        DynaflowHookManager $manager,
        string|array $stepIdentifier,
        ?string $topic = null,
        ?string $action = null
    ) {
        $this->manager        = $manager;
        $this->stepIdentifier = $stepIdentifier;
        $this->topic          = $topic;
        $this->action         = $action;
    }

    /**
     * Register the step activated hook.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        if ($this->topic !== null && $this->action !== null) {
            // Scoped registration
            $this->manager->onStepActivatedFor(
                $this->topic,
                $this->action,
                $this->stepIdentifier,
                $callback
            );
        } else {
            // Global registration
            $this->manager->onStepActivated($this->stepIdentifier, $callback);
        }
    }
}
