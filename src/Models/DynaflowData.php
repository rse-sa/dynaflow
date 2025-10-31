<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(DynaflowDataFactory::class)]
class DynaflowData extends Model
{
    protected $table = 'dynaflow_data';

    protected $fillable = [
        'dynaflow_instance_id',
        'data',
        'applied',
    ];

    protected $casts = [
        'data'    => 'array',
        'applied' => 'boolean',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(DynaflowInstance::class, 'dynaflow_instance_id');
    }
}
