<?php

namespace RSE\DynaFlow\Concerns;

use Illuminate\Database\Eloquent\Builder;
use RSE\DynaFlow\DynaflowHookManager;

/**
 * Applies every WorkflowQueryScope registered via
 * `DynaflowHookManager::registerQueryScope()` (or the Dynaflow facade).
 *
 * Opt-in local scope: `Model::query()->applyRegisteredScopes()`. The chain
 * is empty until a consumer app registers a scope, so this is a no-op for
 * every dynaflow consumer that hasn't opted in.
 */
trait HasRegisteredQueryScopes
{
    public function scopeApplyRegisteredScopes(Builder $query): Builder
    {
        foreach (app(DynaflowHookManager::class)->getQueryScopes() as $scopeKey) {
            app($scopeKey)->apply($query, $this->getTable());
        }

        return $query;
    }
}
