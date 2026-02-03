<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for after transition hooks.
 */
class AfterTransitionHookBuilder
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
        $this->manager = $manager;
        $this->topic   = $topic;
        $this->action  = $action;

        // Prefix with action if scoped
        if ($this->action !== null) {
            $this->stepIdentifier = $this->prefixSteps($stepIdentifier);
        } else {
            $this->stepIdentifier = $stepIdentifier;
        }
    }

    /**
     * Prefix step identifiers with action for scoped hooks.
     */
    protected function prefixSteps(string|array $steps): string|array
    {
        if (is_array($steps)) {
            return array_map(fn ($step) => $this->action . ':' . $step, $steps);
        }

        return $this->action . ':' . $steps;
    }

    /**
     * Register the after transition hook.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        $this->manager->afterTransitionTo($this->stepIdentifier, $callback);
    }
}
