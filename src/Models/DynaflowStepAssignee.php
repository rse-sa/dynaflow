<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[UseFactory(DynaflowStepAssigneeFactory::class)]
class DynaflowStepAssignee extends Model
{
    protected $fillable = [
        'dynaflow_step_id',
        'assignable_type',
        'assignable_id',
    ];

    public function dynaflowStep(): BelongsTo
    {
        return $this->belongsTo(DynaflowStep::class);
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
