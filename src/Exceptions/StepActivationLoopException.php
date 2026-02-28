<?php

namespace RSE\DynaFlow\Exceptions;

use RuntimeException;

/**
 * Thrown when a step's onStepActivated hook calls transitionTo() in a way
 * that would re-activate the same step, creating an infinite loop.
 *
 * Common causes:
 *   - A hook transitions to a non-final step whose activation eventually
 *     routes back to the original step.
 *   - A hook calls transitionTo() with the same step as the target (self-loop).
 *
 * Fix: ensure that transitionTo() calls inside onStepActivated hooks always
 * advance the workflow forward and never route back to a step that is already
 * being activated in the current call stack.
 */
class StepActivationLoopException extends RuntimeException
{
    public function __construct(string $instanceId, string $stepKey)
    {
        parent::__construct(
            "Infinite loop detected: step '{$stepKey}' on instance #{$instanceId} was re-activated "
            . "while its onStepActivated hooks were still executing. "
            . "A hook is calling transitionTo() in a way that routes back to the same step. "
            . "Ensure all transitionTo() calls inside onStepActivated advance the workflow forward."
        );
    }
}
