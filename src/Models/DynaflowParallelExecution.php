<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks parallel execution branches within a workflow instance.
 *
 * @property int $id
 * @property int $dynaflow_instance_id
 * @property string $group_id
 * @property string $branch_key
 * @property int $step_id
 * @property string $status
 * @property array|null $result
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $joined_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DynaflowParallelExecution extends Model
{
    protected $table = 'dynaflow_parallel_executions';

    protected $fillable = [
        'dynaflow_instance_id',
        'group_id',
        'branch_key',
        'step_id',
        'status',
        'result',
        'started_at',
        'completed_at',
        'joined_at',
    ];

    protected $casts = [
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    /**
     * Status constants.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_JOINED = 'joined';

    /**
     * Get the workflow instance this parallel execution belongs to.
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(DynaflowInstance::class, 'dynaflow_instance_id');
    }

    /**
     * Get the step for this branch.
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(DynaflowStep::class, 'step_id');
    }

    /**
     * Check if this branch is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this branch is running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if this branch is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if this branch has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark the branch as running.
     */
    public function markRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
    }

    /**
     * Mark the branch as completed with result.
     */
    public function markCompleted(array $result = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the branch as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'result' => ['error' => $error],
            'completed_at' => now(),
        ]);
    }

    /**
     * Scope to get executions for a specific group.
     */
    public function scopeForGroup($query, string $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * Scope to get pending executions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed executions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
