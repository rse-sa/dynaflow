<?php

namespace RSE\DynaFlow\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RSE\DynaFlow\Enums\DynaflowStatus;

trait HasDynaflows
{
    public function dynaflowInstances(): MorphMany
    {
        return $this->morphMany(dynaflowInstanceModel(), 'model');
    }

    public function pendingDynaflows(): MorphMany
    {
        return $this->dynaflowInstances()->where('status', DynaflowStatus::PENDING->value);
    }

    public function pendingDynaflow(): MorphOne
    {
        return $this
            ->morphOne(dynaflowInstanceModel(), 'model')
            ->where('status', DynaflowStatus::PENDING->value)
            ->latestOfMany();
    }

    public function getWithPendingChanges(): array
    {
        $pendingDynaflow = $this->pendingDynaflows()
            ->with('dynaflowData')
            ->latest()
            ->first();

        if (! $pendingDynaflow || ! $pendingDynaflow->dynaflowData) {
            return $this->toArray();
        }

        return array_merge($this->toArray(), $pendingDynaflow->dynaflowData->data);
    }

    public function hasPendingDynaflow(): bool
    {
        return $this->pendingDynaflows()->exists();
    }
}
