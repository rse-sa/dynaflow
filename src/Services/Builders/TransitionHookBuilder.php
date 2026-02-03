<?php

namespace RSE\DynaFlow\Services\Builders;

use Closure;
use InvalidArgumentException;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Builder for transition hooks (from/to pattern).
 */
class TransitionHookBuilder
{
    protected DynaflowHookManager $manager;

    protected string|array|null $from = null;

    protected string|array|null $to = null;

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
    }

    /**
     * Set the source step(s).
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or array of identifiers
     * @return $this
     */
    public function from(string|array $stepIdentifier): static
    {
        // Prefix with action if scoped
        if ($this->action !== null) {
            $this->from = $this->prefixSteps($stepIdentifier);
        } else {
            $this->from = $stepIdentifier;
        }

        return $this;
    }

    /**
     * Set the target step(s).
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or array of identifiers
     * @return $this
     */
    public function to(string|array $stepIdentifier): static
    {
        // Prefix with action if scoped
        if ($this->action !== null) {
            $this->to = $this->prefixSteps($stepIdentifier);
        } else {
            $this->to = $stepIdentifier;
        }

        return $this;
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
     * Set the source and target step(s).
     *
     * @param  string|array  $sourceStepIdentifier  Step ID, key, or array of identifiers
     * @param  string|array  $targetStepIdentifier  Step ID, key, or array of identifiers
     * @return $this
     */
    public function between(string|array $sourceStepIdentifier, string|array $targetStepIdentifier = '*'): static
    {
        return $this->from($sourceStepIdentifier)->to($targetStepIdentifier);
    }

    /**
     * Register the transition hook.
     *
     * @param  Closure  $callback  The callback to execute
     */
    public function execute(Closure $callback): void
    {
        if ($this->from === null || $this->to === null) {
            throw new InvalidArgumentException(
                'Both from() and to() must be called before execute()'
            );
        }

        $this->manager->onTransition($this->from, $this->to, $callback);
    }
}
