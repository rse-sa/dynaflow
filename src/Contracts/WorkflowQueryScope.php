<?php

namespace RSE\DynaFlow\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * A chainable, app-registered scope applied to every dynaflow model read
 * that opts in via the `applyRegisteredScopes()` local scope.
 *
 * Consumer apps register scopes to add tenant isolation, site scoping, or
 * any other cross-cutting query constraint without forking the package.
 */
interface WorkflowQueryScope
{
    public function apply(Builder $query, string $table): void;
}
