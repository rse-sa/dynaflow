<?php

namespace RSE\DynaFlow\Contracts;

/**
 * Data Transfer Object for action handler execution results.
 *
 * ActionResult encapsulates the outcome of an action handler execution,
 * including success/failure status, routing decisions, and output data.
 *
 * Result statuses:
 * - success: Action completed, continue to next step
 * - failed: Action failed, workflow may need error handling
 * - waiting: Action requires async completion (sub-workflow, external callback)
 * - forked: Action created parallel branches
 * - route_to: Action determined specific next step (for decision nodes)
 */
class ActionResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_FORKED = 'forked';

    public const STATUS_ROUTE_TO = 'route_to';

    protected function __construct(
        protected string $status,
        protected array $data = [],
        protected ?string $route = null,
        protected ?string $error = null
    ) {}

    /**
     * Create a successful result.
     *
     * @param  array  $data  Output data from the action
     */
    public static function success(array $data = []): self
    {
        return new self(self::STATUS_SUCCESS, $data);
    }

    /**
     * Create a failed result.
     *
     * @param  string  $error  Error message
     * @param  array  $data  Additional error context
     */
    public static function failed(string $error, array $data = []): self
    {
        return new self(self::STATUS_FAILED, $data, null, $error);
    }

    /**
     * Create a result that routes to a specific next step.
     *
     * Used by decision/conditional handlers to determine the next step.
     *
     * @param  string  $stepKey  The key of the step to route to
     * @param  array  $data  Additional data to pass
     */
    public static function routeTo(string $stepKey, array $data = []): self
    {
        return new self(self::STATUS_ROUTE_TO, $data, $stepKey);
    }

    /**
     * Create a waiting result for async operations.
     *
     * Used when the action needs to wait for external completion
     * (e.g., sub-workflow, external webhook callback).
     *
     * @param  array  $data  Data about what we're waiting for
     */
    public static function waiting(array $data = []): self
    {
        return new self(self::STATUS_WAITING, $data);
    }

    /**
     * Create a forked result for parallel execution.
     *
     * Used by parallel gateway to indicate branches were created.
     *
     * @param  array  $data  Data about the forked branches
     */
    public static function forked(array $data = []): self
    {
        return new self(self::STATUS_FORKED, $data);
    }

    /**
     * Check if the result indicates success.
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if the result indicates failure.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the result indicates waiting for async completion.
     */
    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    /**
     * Check if the result indicates parallel forking.
     */
    public function isForked(): bool
    {
        return $this->status === self::STATUS_FORKED;
    }

    /**
     * Check if the result specifies a routing target.
     */
    public function hasRoute(): bool
    {
        return $this->status === self::STATUS_ROUTE_TO && $this->route !== null;
    }

    /**
     * Check if execution should continue to next step.
     *
     * Returns true for success and route_to statuses.
     */
    public function shouldContinue(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_ROUTE_TO], true);
    }

    /**
     * Get the result status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the output data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get a specific data value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Get the routing target step key.
     */
    public function getRoute(): ?string
    {
        return $this->route;
    }

    /**
     * Get the error message.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Convert result to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'data'   => $this->data,
            'route'  => $this->route,
            'error'  => $this->error,
        ];
    }
}
