<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RSE\DynaFlow\Concerns\HasRegisteredQueryScopes;

#[UseFactory(DynaflowStepAssigneeFactory::class)]
class DynaflowStepAssignee extends Model
{
    use HasRegisteredQueryScopes;

    protected $fillable = [
        'dynaflow_step_id',
        'dynaflow_instance_id',
        'assignable_type',
        'assignable_id',
    ];

    public function dynaflowStep(): BelongsTo
    {
        return $this->belongsTo(DynaflowStep::class);
    }

    public function dynaflowInstance(): BelongsTo
    {
        return $this->belongsTo(DynaflowInstance::class);
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Narrow the query to rows valid for the given instance: rows explicitly
     * tagged with `dynaflow_instance_id = $instanceId` (dynamically resolved
     * assignees) OR rows with a NULL `dynaflow_instance_id` (classic static
     * assignees, valid for every instance). The two coexist by design — a
     * step can have both a hand-picked static approver AND a dynamically
     * resolved one, and both should authorize.
     */
    public function scopeForInstance(Builder $query, int|string $instanceId): Builder
    {
        return $query->where(function (Builder $q) use ($instanceId): void {
            $q->where('dynaflow_instance_id', $instanceId)
                ->orWhereNull('dynaflow_instance_id');
        });
    }
}
