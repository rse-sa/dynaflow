<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RSE\DynaFlow\Concerns\HasRegisteredQueryScopes;
use RSE\DynaFlow\Database\Factories\DynaflowInstanceFactory;
use RSE\DynaFlow\Enums\DynaflowStatus;

/**
 * @property \Illuminate\Contracts\Auth\Authenticatable|\App\Models\User $triggeredBy
 */
#[UseFactory(DynaflowInstanceFactory::class)]
class DynaflowInstance extends Model
{
    use HasFactory;
    use HasRegisteredQueryScopes;

    protected $fillable = [
        'dynaflow_id',
        'model_type',
        'model_id',
        'status',
        'triggered_by_type',
        'triggered_by_id',
        'data',
        'metadata',
        'current_step_id',
        'step_started_at',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'metadata'        => 'json',
        'step_started_at' => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    public function dynaflow(): BelongsTo
    {
        return $this->belongsTo(Dynaflow::class);
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggeredBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(DynaflowStep::class, 'current_step_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(DynaflowStepExecution::class)->orderBy('executed_at');
    }

    public function dynaflowData(): HasOne
    {
        return $this->hasOne(dynaflowDataModel());
    }

    /**
     * Opaque tenant identifier carried by queued jobs so a consumer app's
     * JobTenantResolver can wrap job execution in the right context.
     * Dynaflow has no concept of a tenant — this is a hook, not a column.
     * Consumer apps override this in their model subclass.
     */
    public function tenantContext(): mixed
    {
        return null;
    }

    public function scopePending(Builder $builder): Builder
    {
        return $builder->where('status', DynaflowStatus::PENDING->value);
    }

    public function scopeCompleted(Builder $builder): Builder
    {
        return $builder->whereIn('status', DynaflowStatus::getSuccessStatuses());
    }

    public function scopeApproved(Builder $builder): Builder
    {
        return $builder->whereIn('status', DynaflowStatus::getSuccessStatuses());
    }

    public function scopeRejected(Builder $builder): Builder
    {
        return $builder->whereIn('status', [
            DynaflowStatus::REJECTED->value,
            DynaflowStatus::AUTO_REJECTED->value,
        ]);
    }

    public function scopeTerminated(Builder $builder): Builder
    {
        return $builder->whereIn('status', DynaflowStatus::getFailureStatuses());
    }

    public function scopeAutoApproved(Builder $builder): Builder
    {
        return $builder->where('status', DynaflowStatus::AUTO_APPROVED->value);
    }

    public function scopeCancelled(Builder $builder): Builder
    {
        return $builder->where('status', DynaflowStatus::CANCELLED->value);
    }

    public function scopeWithTopic(Builder $builder, string $topic): Builder
    {
        return $builder->whereRelation('dynaflow', 'topic', $topic);
    }

    public function scopeClosed(Builder $builder): Builder
    {
        return $builder->where('status', '!=', DynaflowStatus::PENDING->value);
    }

    public function isPending(): bool
    {
        return $this->status === DynaflowStatus::PENDING->value;
    }

    public function isClosed(): bool
    {
        return !$this->isPending();
    }

    public function isCompleted(): bool
    {
        return DynaflowStatus::isSuccessful($this->status);
    }

    public function isApproved(): bool
    {
        return $this->isCompleted();
    }

    public function isRejected(): bool
    {
        return in_array($this->status, [
            DynaflowStatus::REJECTED->value,
            DynaflowStatus::AUTO_REJECTED->value,
        ]);
    }

    public function isTerminated(): bool
    {
        return DynaflowStatus::isFailure($this->status);
    }

    public function isCancelled(): bool
    {
        return $this->status === DynaflowStatus::CANCELLED->value;
    }

    public function isAutoApproved(): bool
    {
        return $this->status === DynaflowStatus::AUTO_APPROVED->value;
    }

    public function isAutoRejected(): bool
    {
        return $this->status === DynaflowStatus::AUTO_REJECTED->value;
    }
}
