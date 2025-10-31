<?php

namespace RSE\DynaFlow\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RSE\DynaFlow\Models\DynaflowInstance;

trait HasDynaflows
{
    public function dynaflowInstances(): MorphMany
    {
        return $this->morphMany(DynaflowInstance::class, 'model');
    }

    public function pendingDynaflows(): MorphMany
    {
        return $this->dynaflowInstances()->where('status', 'pending');
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
