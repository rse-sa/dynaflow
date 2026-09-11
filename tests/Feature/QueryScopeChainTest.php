<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Contracts\WorkflowQueryScope;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Tests\TestCase;

class QueryScopeChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_every_registered_scope_on_workflow_reads(): void
    {
        $spy = new class implements WorkflowQueryScope
        {
            public array $calls = [];

            public function apply(Builder $query, string $table): void
            {
                $this->calls[] = $table;
                $query->whereRaw('1 = 0');
            }
        };

        app()->instance('test.spy', $spy);
        DynaflowFacade::registerQueryScope('test.spy');

        Dynaflow::factory()->create([
            'topic'  => 'x',
            'action' => 'y',
            'active' => true,
        ]);

        $result = Dynaflow::query()->applyRegisteredScopes()->get();

        $this->assertCount(0, $result);
        $this->assertContains('dynaflows', $spy->calls);
    }

    public function test_scope_chain_is_empty_by_default_and_reads_are_unaffected(): void
    {
        Dynaflow::factory()->create(['topic' => 'x', 'action' => 'y']);

        $result = Dynaflow::query()->applyRegisteredScopes()->get();

        $this->assertCount(1, $result);
    }
}
