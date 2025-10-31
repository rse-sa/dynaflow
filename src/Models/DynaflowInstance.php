<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RSE\DynaFlow\Database\Factories\DynaflowInstanceFactory;

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
        'current_step_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
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

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
