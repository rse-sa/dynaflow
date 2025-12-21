<?php

namespace RSE\DynaFlow\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait for models that support draft records.
 *
 * Add this trait to models that need workflow draft functionality.
 * Requires migrations to add `is_draft` and `replaces_id` columns.
 */
trait HasDrafts
{
    /**
     * Boot the HasDrafts trait.
     * Adds global scope to hide draft records by default.
     */
    protected static function bootHasDrafts(): void
    {
        static::addGlobalScope('non_draft', function (Builder $builder) {
            $builder->where(function ($query) {
                $query->where($query->getModel()->getTable().'.is_draft', false)
                    ->orWhereNull($query->getModel()->getTable().'.is_draft');
            });
        });
    }

    /**
     * Query including draft records.
     *
     * @return Builder
     */
    public static function withDrafts(): Builder
    {
        return static::withoutGlobalScope('non_draft');
    }

    /**
     * Query only draft records.
     *
     * @return Builder
     */
    public static function onlyDrafts(): Builder
    {
        return static::withoutGlobalScope('non_draft')
            ->where('is_draft', true);
    }

    /**
     * Check if this record is a draft.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->is_draft ?? false;
    }

    /**
     * Get the original record that this draft replaces (for updates).
     *
     * @return static|null
     */
    public function replacedRecord()
    {
        if (! $this->replaces_id) {
            return null;
        }

        return static::withDrafts()->find($this->replaces_id);
    }
}
