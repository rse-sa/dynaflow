<?php

namespace RSE\DynaFlow\Contracts;

/**
 * Wraps queued-job execution in whatever tenant context the consuming app
 * needs (e.g. multi-company scoping). Dynaflow itself has no concept of a
 * tenant — the job payload just carries an opaque `tenantContext` value and
 * this resolver decides what to do with it.
 *
 * The default binding is a no-op that just calls $work().
 */
interface JobTenantResolver
{
    public function runInTenantContext(mixed $tenantContext, callable $work): mixed;
}
