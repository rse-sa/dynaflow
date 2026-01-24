<?php

namespace RSE\DynaFlow\Actions;

use Carbon\Carbon;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Action handler for introducing delays in workflow execution.
 *
 * Supports:
 * - Fixed duration delays (seconds, minutes, hours, days)
 * - Delay until specific time
 * - Business hours consideration (optional)
 *
 * The delay is implemented by returning a "waiting" result with
 * resume time information. The AutoStepExecutor will schedule
 * a job to resume the workflow after the delay.
 *
 * Configuration example:
 * ```php
 * [
 *     'duration' => 30,
 *     'unit' => 'minutes', // seconds, minutes, hours, days
 *     // OR
 *     'until' => '{{model.due_date}}',
 * ]
 * ```
 */
class DelayActionHandler implements ActionHandler
{
    public function __construct(
        protected PlaceholderResolver $resolver
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->action_config ?? [];

        try {
            $resumeAt = $this->calculateResumeTime($config, $ctx);

            if ($resumeAt === null) {
                return ActionResult::failed('Could not determine delay duration');
            }

            // If resume time is in the past or now, continue immediately
            if ($resumeAt->isPast() || $resumeAt->isNow()) {
                return ActionResult::success([
                    'delayed'      => false,
                    'resume_at'    => $resumeAt->toIso8601String(),
                    'message'      => 'Delay time already passed, continuing immediately',
                ]);
            }

            // Return waiting result - the executor will handle scheduling
            return ActionResult::waiting([
                'resume_at'        => $resumeAt->toIso8601String(),
                'delay_seconds'    => now()->diffInSeconds($resumeAt),
                'waiting_for'      => 'delay',
                'message'          => "Waiting until {$resumeAt->toDateTimeString()}",
            ]);
        } catch (\Throwable $e) {
            return ActionResult::failed('Failed to process delay: ' . $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Calculate when the workflow should resume.
     */
    protected function calculateResumeTime(array $config, DynaflowContext $ctx): ?Carbon
    {
        // Check for "until" specific time
        if (! empty($config['until'])) {
            $until = $this->resolver->resolve($config['until'], $ctx);

            if ($until) {
                return Carbon::parse($until);
            }
        }

        // Check for duration-based delay
        $duration = (int) ($config['duration'] ?? 0);
        $unit     = $config['unit'] ?? 'minutes';

        if ($duration <= 0) {
            return null;
        }

        $resumeAt = now();

        return match ($unit) {
            'seconds' => $resumeAt->addSeconds($duration),
            'minutes' => $resumeAt->addMinutes($duration),
            'hours'   => $resumeAt->addHours($duration),
            'days'    => $resumeAt->addDays($duration),
            'weeks'   => $resumeAt->addWeeks($duration),
            default   => $resumeAt->addMinutes($duration),
        };
    }

    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'duration' => [
                    'type'        => 'integer',
                    'title'       => 'Duration',
                    'description' => 'How long to delay',
                    'minimum'     => 0,
                ],
                'unit' => [
                    'type'        => 'string',
                    'title'       => 'Unit',
                    'enum'        => ['seconds', 'minutes', 'hours', 'days', 'weeks'],
                    'default'     => 'minutes',
                    'description' => 'Time unit for duration',
                ],
                'until' => [
                    'type'        => 'string',
                    'title'       => 'Until',
                    'description' => 'Specific datetime to wait until (overrides duration)',
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Delay';
    }

    public function getDescription(): string
    {
        return 'Pause workflow execution for a specified duration or until a specific time.';
    }

    public function getCategory(): string
    {
        return 'flow';
    }

    public function getIcon(): string
    {
        return 'clock';
    }

    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resume_at' => [
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => 'When the workflow will resume',
                ],
                'delay_seconds' => [
                    'type'        => 'integer',
                    'description' => 'Total delay in seconds',
                ],
            ],
        ];
    }
}
