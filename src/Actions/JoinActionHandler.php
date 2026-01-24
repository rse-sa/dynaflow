<?php

namespace RSE\DynaFlow\Actions;

use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\DynaflowParallelExecution;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Join Action Handler - Synchronization Gateway
 *
 * Waits for parallel branches to complete before continuing.
 * Acts as the merge point for parallel executions.
 */
class JoinActionHandler implements ActionHandler
{
    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->getActionConfig();
        $groupId = $config['group_id'] ?? $this->findActiveGroupId($ctx);

        if (! $groupId) {
            return ActionResult::failed('No parallel execution group found');
        }

        $instance = $ctx->instance;
        $metadata = $instance->metadata ?? [];
        $parallelState = $metadata['parallel_executions'][$groupId] ?? null;

        if (! $parallelState) {
            return ActionResult::failed("Parallel execution group '$groupId' not found");
        }

        // Get all parallel executions for this group
        $executions = DynaflowParallelExecution::where('dynaflow_instance_id', $instance->id)
            ->where('group_id', $groupId)
            ->get();

        $completedCount = $executions->where('status', 'completed')->count();
        $totalCount = $executions->count();
        $waitForAll = $config['wait_for_all'] ?? true;

        // Check if we should continue
        $shouldContinue = $waitForAll
            ? $completedCount === $totalCount
            : $completedCount > 0;

        if (! $shouldContinue) {
            // Not all branches complete yet, wait
            return ActionResult::waiting([
                'group_id' => $groupId,
                'completed' => $completedCount,
                'total' => $totalCount,
                'waiting_for' => $waitForAll ? 'all' : 'any',
            ]);
        }

        // All required branches complete, merge results
        $branchResults = $executions->mapWithKeys(function ($exec) {
            return [$exec->branch_key => $exec->result ?? []];
        })->toArray();

        // Clean up parallel state
        unset($metadata['parallel_executions'][$groupId]);
        $instance->update(['metadata' => $metadata]);

        // Mark parallel executions as joined
        $executions->each(function ($exec) {
            $exec->update(['status' => 'joined', 'joined_at' => now()]);
        });

        return ActionResult::success([
            'group_id' => $groupId,
            'merged_results' => $branchResults,
            'branch_count' => $totalCount,
        ]);
    }

    /**
     * Find the active parallel group ID from instance metadata.
     */
    protected function findActiveGroupId(DynaflowContext $ctx): ?string
    {
        $metadata = $ctx->instance->metadata ?? [];
        $parallelExecutions = $metadata['parallel_executions'] ?? [];

        // Return the first (or most recent) active group
        return array_key_first($parallelExecutions);
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group_id' => [
                    'type' => 'string',
                    'title' => 'Group ID',
                    'description' => 'The parallel execution group to join. Leave empty to auto-detect.',
                ],
                'wait_for_all' => [
                    'type' => 'boolean',
                    'title' => 'Wait for All',
                    'description' => 'If true, wait for all branches. If false, continue when first completes.',
                    'default' => true,
                ],
                'timeout_minutes' => [
                    'type' => 'integer',
                    'title' => 'Timeout (Minutes)',
                    'description' => 'Maximum time to wait for branches before timing out.',
                    'default' => 60,
                ],
            ],
        ];
    }

    public function getLabel(): string
    {
        return 'Parallel Join';
    }

    public function getDescription(): string
    {
        return 'Wait for parallel branches to complete and merge results';
    }

    public function getCategory(): string
    {
        return 'Flow Control';
    }

    public function getIcon(): string
    {
        return 'git-merge';
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group_id' => ['type' => 'string', 'description' => 'The parallel execution group ID'],
                'merged_results' => ['type' => 'object', 'description' => 'Results from all branches, keyed by branch'],
                'branch_count' => ['type' => 'integer', 'description' => 'Number of branches that were joined'],
            ],
        ];
    }
}
