<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RSE\DynaFlow\Database\Factories\DynaflowFactory;
use Spatie\Translatable\HasTranslations;

#[UseFactory(DynaflowFactory::class)]
class Dynaflow extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'name',
        'topic',
        'action',
        'description',
        'active',
        'overridden_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public array $translatable = ['name', 'description'];

    public function steps(): HasMany
    {
        return $this->hasMany(DynaflowStep::class)->orderBy('order');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(DynaflowInstance::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(DynaflowException::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(Dynaflow::class, 'overridden_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(Dynaflow::class, 'overridden_by');
    }
}
