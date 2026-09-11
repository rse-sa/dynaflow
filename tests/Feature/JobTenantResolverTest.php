<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Contracts\JobTenantResolver;
use RSE\DynaFlow\Jobs\ExecuteAutoStepJob;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\AutoStepExecutor;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class JobTenantResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_binding_is_a_no_op_that_just_calls_the_work_closure(): void
    {
        $resolver = app(JobTenantResolver::class);

        $ran    = false;
        $result = $resolver->runInTenantContext(42, function () use (&$ran) {
            $ran = true;

            return 'done';
        });

        $this->assertTrue($ran);
        $this->assertEquals('done', $result);
    }

    public function test_execute_auto_step_job_wraps_handle_in_the_bound_tenant_resolver_with_its_tenant_context(): void
    {
        $spy = new class implements JobTenantResolver
        {
            public mixed $seenTenantContext = null;

            public bool $workWasCalled = false;

            public function runInTenantContext(mixed $tenantContext, callable $work): mixed
            {
                $this->seenTenantContext = $tenantContext;
                $this->workWasCalled     = true;

                return $work();
            }
        };

        app()->instance(JobTenantResolver::class, $spy);

        $user     = User::factory()->create();
        $workflow = Dynaflow::factory()->create();
        $step     = DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id]);

        // Status deliberately not 'pending': the job's own guard clause returns
        // early once inside the resolver's work closure. What this test proves
        // is that the resolver wraps that work at all, with the right context —
        // not the auto-step business logic itself (covered elsewhere).
        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $workflow->id,
            'status'            => 'cancelled',
            'current_step_id'   => $step->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $job = new ExecuteAutoStepJob($instance, $step, $user, tenantContext: 42);
        $job->handle(app(AutoStepExecutor::class));

        $this->assertEquals(42, $spy->seenTenantContext);
        $this->assertTrue($spy->workWasCalled);
    }
}
