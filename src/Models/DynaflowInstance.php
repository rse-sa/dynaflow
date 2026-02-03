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
use RSE\DynaFlow\Database\Factories\DynaflowInstanceFactory;

/**
 * @property \Illuminate\Contracts\Auth\Authenticatable|\App\Models\User $triggeredBy
 */
#[UseFactory(DynaflowInstanceFactory::class)]
class DynaflowInstance extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'data'            => 'json',
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
        return $this->hasOne(DynaflowData::class);
    }

    public function scopePending(Builder $builder): Builder
    {
        return $builder->where('status', 'pending');
    }

    public function scopeCompleted(Builder $builder): Builder
    {
        return $builder->whereIn('status', ['completed', 'auto_approved']);
    }

    public function scopeAutoApproved(Builder $builder): Builder
    {
        return $builder->where('status', 'auto_approved');
    }

    public function scopeCancelled(Builder $builder): Builder
    {
        return $builder->where('status', 'cancelled');
    }

    public function scopeWithTopic(Builder $builder, string $topic): Builder
    {
        return $builder->whereRelation('dynaflow', 'topic', $topic);
    }

    public function isPending(): bool
    {
        return $this->status == 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status == 'completed' || $this->status == 'auto_approved';
    }

    public function isCancelled(): bool
    {
        return $this->status == 'cancelled';
    }

    public function isAutoApproved(): bool
    {
        return $this->status == 'auto_approved';
    }
}
