<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\TestCase;

class EngineIsRequestScopedTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_is_scoped_not_singleton_across_scope_boundaries(): void
    {
        $first = app(DynaflowEngine::class);

        $this->assertSame($first, app(DynaflowEngine::class));

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(DynaflowEngine::class));
    }
}
