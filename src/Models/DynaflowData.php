<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RSE\DynaFlow\Database\Factories\DynaflowDataFactory;

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
        return $this->belongsTo(dynaflowInstanceModel(), 'dynaflow_instance_id');
    }

    /**
     * Get model data from the data array.
     */
    public function getModelData(): array
    {
        return $this->data['model'] ?? [];
    }

    /**
     * Get relationship data from the data array.
     *
     * @param  string|null  $name  If specified, returns only that relationship's data
     */
    public function getRelationshipData(?string $name = null): array
    {
        $relationships = $this->data['relationships'] ?? [];

        return $name ? ($relationships[$name] ?? []) : $relationships;
    }

    /**
     * Check if data has a specific relationship.
     */
    public function hasRelationship(string $name): bool
    {
        return isset($this->data['relationships'][$name]);
    }
}
