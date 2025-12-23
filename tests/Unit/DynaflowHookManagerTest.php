<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Support\DynaflowContext;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowHookManagerTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowHookManager $hookManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hookManager = new DynaflowHookManager;
    }

    public function test_can_register_before_step_hook()
    {
        $called = false;

        $this->hookManager->beforeTransitionTo('*', function () use (&$called) {
            $called = true;
        });

        $sourceStep = DynaflowStep::factory()->create();
        $targetStep = DynaflowStep::factory()->create();
        $instance   = DynaflowInstance::factory()->create();
        $user       = User::factory()->create();

        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $targetStep,
            decision: 'approved',
            user: $user,
            sourceStep: $sourceStep
        );

        $this->hookManager->runBeforeTransitionToHooks($ctx);

        $this->assertTrue($called);
    }

    public function test_before_hook_can_block_execution()
    {
        $this->hookManager->beforeTransitionTo('*', function () {
            return false;
        });

        $sourceStep = DynaflowStep::factory()->create();
        $targetStep = DynaflowStep::factory()->create();
        $instance   = DynaflowInstance::factory()->create();
        $user       = User::factory()->create();

        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $targetStep,
            decision: 'approved',
            user: $user,
            sourceStep: $sourceStep
        );

        $result = $this->hookManager->runBeforeTransitionToHooks($ctx);

        $this->assertFalse($result);
    }

    public function test_can_register_after_step_hook()
    {
        $called = false;

        $this->hookManager->afterTransitionTo('*', function () use (&$called) {
            $called = true;
        });

        $sourceStep = DynaflowStep::factory()->create();
        $targetStep = DynaflowStep::factory()->create();
        $instance   = DynaflowInstance::factory()->create();
        $user       = User::factory()->create();
        $execution  = DynaflowStepExecution::factory()->create();

        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $targetStep,
            decision: 'approved',
            user: $user,
            sourceStep: $sourceStep,
            execution: $execution
        );

        $this->hookManager->runAfterTransitionToHooks($ctx);

        $this->assertTrue($called);
    }

    public function test_can_register_transition_hook()
    {
        $called = false;

        $this->hookManager->onTransition('*', '*', function () use (&$called) {
            $called = true;
        });

        $step1    = DynaflowStep::factory()->create();
        $step2    = DynaflowStep::factory()->create();
        $instance = DynaflowInstance::factory()->create();
        $user     = User::factory()->create();

        $ctx = new DynaflowContext(
            instance: $instance,
            targetStep: $step2,
            decision: 'approved',
            user: $user,
            sourceStep: $step1
        );

        $this->hookManager->runTransitionHooks($ctx);

        $this->assertTrue($called);
    }

    public function test_can_register_authorization_resolver()
    {
        $this->hookManager->authorizeStepUsing(function ($step, $user) {
            return $user->getKey() === 1;
        });

        $step = DynaflowStep::factory()->create();
        $user = User::factory()->create(['id' => 1]);

        $result = $this->hookManager->resolveAuthorization($step, $user);

        $this->assertTrue($result);
    }

    public function test_can_register_exception_resolver()
    {
        $this->hookManager->exceptionUsing(function ($dynaflow, $user) {
            return $user->getKey() === 1;
        });

        $dynaflow = \RSE\DynaFlow\Models\Dynaflow::factory()->create();
        $user     = User::factory()->create(['id' => 1]);

        $result = $this->hookManager->resolveException($dynaflow, $user);

        $this->assertTrue($result);
    }

    public function test_can_register_assignee_resolver()
    {
        $this->hookManager->resolveAssigneesUsing(function ($step, $user) {
            return [1, 2, 3];
        });

        $step = DynaflowStep::factory()->create();
        $user = User::factory()->create();

        $result = $this->hookManager->resolveAssignees($step, $user);

        $this->assertEquals([1, 2, 3], $result);
    }
}
