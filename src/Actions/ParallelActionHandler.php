<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Jobs\ExecuteAutoStepJob;
use RSE\DynaFlow\Models\DynaflowParallelExecution;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Parallel Action Handler - Fork Gateway
 *
 * Spawns multiple parallel execution branches from a single step.
 * Each branch executes independently and joins at a JoinActionHandler.
 */
class ParallelActionHandler implements ActionHandler
{
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $branches = $config['branches'] ?? [];

        if (empty($branches)) {
            // If no branches configured, use allowed transitions as branches
            $branches = $step->allowedTransitions()
                ->pluck('key')
                ->toArray();
        }

        if (empty($branches)) {
            return ActionResult::failed('No parallel branches configured');
        }

        $instance = $ctx->instance;
        $workflow = $instance->dynaflow;

        // Create a parallel execution group
        $groupId = uniqid('parallel_', true);

        // Store parallel execution state in instance metadata
        $metadata = $instance->metadata ?? [];
        $metadata['parallel_executions'][$groupId] = [
            'fork_step_id' => $step->id,
            'branches' => $branches,
            'completed_branches' => [],
            'started_at' => now()->toIso8601String(),
        ];
        $instance->update(['metadata' => $metadata]);

        // Create parallel execution records for each branch
        foreach ($branches as $branchKey) {
            $branchStep = $workflow->steps()->where('key', $branchKey)->first();

            if (! $branchStep) {
                continue;
            }

            // Create parallel execution record
            DynaflowParallelExecution::create([
                'dynaflow_instance_id' => $instance->id,
                'group_id' => $groupId,
                'branch_key' => $branchKey,
                'step_id' => $branchStep->id,
                'status' => 'pending',
                'started_at' => now(),
            ]);

            // Dispatch job to execute branch (async)
            ExecuteAutoStepJob::dispatch($instance->fresh(), $branchStep, $ctx->user);
        }

        return ActionResult::forked([
            'group_id' => $groupId,
            'branches' => $branches,
            'branch_count' => count($branches),
        ]);
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'branches' => [
                    'type' => 'array',
                    'title' => 'Parallel Branches',
                    'description' => 'Step keys to execute in parallel. If empty, uses all allowed transitions.',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'wait_for_all' => [
                    'type' => 'boolean',
                    'title' => 'Wait for All',
                    'description' => 'If true, wait for all branches to complete. If false, continue when first completes.',
                    'default' => true,
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Parallel Fork';
    }

    public function getDescription(): string
    {
        return 'Spawn multiple parallel execution branches';
    }

    public function getCategory(): string
    {
        return 'Flow Control';
    }

    public function getIcon(): string
    {
        return 'git-branch';
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group_id' => ['type' => 'string', 'description' => 'Unique ID for this parallel execution group'],
                'branches' => ['type' => 'array', 'description' => 'Branch keys that were spawned'],
                'branch_count' => ['type' => 'integer', 'description' => 'Number of branches spawned'],
            ],
        ];
    }
}
