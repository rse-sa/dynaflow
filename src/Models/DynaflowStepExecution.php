<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RSE\DynaFlow\Database\Factories\DynaflowStepExecutionFactory;

#[UseFactory(DynaflowStepExecutionFactory::class)]
class DynaflowStepExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'dynaflow_instance_id',
        'dynaflow_step_id',
        'executed_by_type',
        'executed_by_id',
        'decision',
        'note',
        'duration_hours',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(DynaflowInstance::class, 'dynaflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(DynaflowStep::class, 'dynaflow_step_id');
    }

    public function executedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
