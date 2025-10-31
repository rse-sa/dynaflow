<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RSE\DynaFlow\Database\Factories\DynaflowStepFactory;
use Spatie\Translatable\HasTranslations;

#[UseFactory(DynaflowStepFactory::class)]
class DynaflowStep extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'dynaflow_id',
        'name',
        'description',
        'order',
        'is_final',
    ];

    protected $casts = [
        'is_final' => 'boolean',
    ];

    public array $translatable = ['name', 'description'];

    public function dynaflow(): BelongsTo
    {
        return $this->belongsTo(Dynaflow::class);
    }

    public function allowedTransitions(): BelongsToMany
    {
        return $this->belongsToMany(
            DynaflowStep::class,
            'dynaflow_step_transitions',
            'from_step_id',
            'to_step_id'
        )->withTimestamps();
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(DynaflowStepAssignee::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(DynaflowStepExecution::class);
    }

    public function canTransitionTo(DynaflowStep $step): bool
    {
        return $this->allowedTransitions()->where('to_step_id', $step->id)->exists();
    }
}
