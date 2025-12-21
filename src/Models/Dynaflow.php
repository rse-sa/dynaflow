<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
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
        'monitored_fields',
        'ignored_fields',
    ];

    protected $casts = [
        'active'           => 'boolean',
        'monitored_fields' => 'array',
        'ignored_fields'   => 'array',
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

    /**
     * Set the fields that should be monitored for changes.
     * Only trigger workflow if these fields are modified.
     *
     * @return $this
     */
    public function setMonitoredFields(array $fields): self
    {
        $this->monitored_fields = $fields;

        return $this;
    }

    /**
     * Set the fields that should be ignored.
     * Skip workflow if only these fields are modified.
     *
     * @return $this
     */
    public function setIgnoredFields(array $fields): self
    {
        $this->ignored_fields = $fields;

        return $this;
    }

    /**
     * Check if workflow should be triggered based on changed fields.
     *
     * @param  array  $originalData  Original model data
     * @param  array  $newData  New data being applied
     */
    public function shouldTriggerForFields(array $originalData, array $newData): bool
    {
        $normalize = function($data){
            foreach ($data as $key => $value) {
                if(is_array($value) && empty($value)){
                    $data[$key] = null;
                }
            }

            return $data;
        };

        $changedFields = collect($normalize(Arr::dot($newData)))
            ->diffAssoc($normalize(Arr::dot($originalData)))
            ->keys()
            ->toArray();

        if (empty($changedFields)) {
            return false; // No changes
        }

        // If monitored_fields is set, only trigger if any monitored field changed
        if (! empty($this->monitored_fields)) {
            return ! empty(array_intersect($changedFields, $this->monitored_fields));
        }

        // If ignored_fields is set, skip if ONLY ignored fields changed
        if (! empty($this->ignored_fields)) {
            $nonIgnoredChanges = array_diff($changedFields, $this->ignored_fields);

            return ! empty($nonIgnoredChanges);
        }

        // No field filtering configured - trigger for any change
        return true;
    }

    protected function getChangedFields($newData, $originalData, $prefix = '')
    {
        $changed = [];

        foreach ($newData as $key => $value) {
            $dottedKey = $prefix ? "{$prefix}.{$key}" : $key;

            // Key doesn't exist in original data
            if (! array_key_exists($key, $originalData)) {
                // If it's an array, add all nested keys
                if (is_array($value)) {
                    $changed = array_merge($changed, $this->getAllNestedKeys($value, $dottedKey));
                } else {
                    $changed[] = $dottedKey;
                }

                continue;
            }

            $oldValue = $originalData[$key];

            // Both are arrays - recurse deeper (DON'T add parent key)
            if (is_array($value) && is_array($oldValue)) {
                $nestedChanges = $this->getChangedFields($value, $oldValue, $dottedKey);
                $changed       = array_merge($changed, $nestedChanges);
            }
            // Simple value comparison
            elseif ($value !== $oldValue) {
                $changed[] = $dottedKey;
            }
        }

        // Check for keys that exist in original but not in new data
        foreach ($originalData as $key => $value) {
            if (! array_key_exists($key, $newData)) {
                $dottedKey = $prefix ? "{$prefix}.{$key}" : $key;
                // If it's an array, add all nested keys
                if (is_array($value)) {
                    $changed = array_merge($changed, $this->getAllNestedKeys($value, $dottedKey));
                } else {
                    $changed[] = $dottedKey;
                }
            }
        }

        return $changed;
    }

    // Helper function to get all nested keys when entire branch is new/removed
    protected function getAllNestedKeys($array, $prefix)
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $dottedKey = "{$prefix}.{$key}";

            if (is_array($value)) {
                $keys = array_merge($keys, $this->getAllNestedKeys($value, $dottedKey));
            } else {
                $keys[] = $dottedKey;
            }
        }

        return $keys;
    }
}
