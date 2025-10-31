<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowException;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(DynaflowValidator::class);
    }

    public function test_user_can_bypass_workflow_with_exception()
    {
        $user     = User::factory()->create();
        $dynaflow = Dynaflow::factory()->create();

        DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
            'starts_at'          => now()->subDay(),
            'ends_at'            => now()->addDay(),
        ]);

        $result = $this->validator->shouldBypassDynaflow($dynaflow, $user);

        $this->assertTrue($result);
    }

    public function test_user_cannot_bypass_workflow_without_exception()
    {
        $user     = User::factory()->create();
        $dynaflow = Dynaflow::factory()->create();

        $result = $this->validator->shouldBypassDynaflow($dynaflow, $user);

        $this->assertFalse($result);
    }

    public function test_exception_is_not_active_before_start_date()
    {
        $user     = User::factory()->create();
        $dynaflow = Dynaflow::factory()->create();

        DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
            'starts_at'          => now()->addDay(),
            'ends_at'            => now()->addDays(2),
        ]);

        $result = $this->validator->shouldBypassDynaflow($dynaflow, $user);

        $this->assertFalse($result);
    }

    public function test_exception_is_not_active_after_end_date()
    {
        $user     = User::factory()->create();
        $dynaflow = Dynaflow::factory()->create();

        DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
            'starts_at'          => now()->subDays(2),
            'ends_at'            => now()->subDay(),
        ]);

        $result = $this->validator->shouldBypassDynaflow($dynaflow, $user);

        $this->assertFalse($result);
    }

    public function test_user_can_execute_step_when_assigned()
    {
        $user = User::factory()->create();
        $step = DynaflowStep::factory()->create();

        $step->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $result = $this->validator->canUserExecuteStep($step, $user);

        $this->assertTrue($result);
    }

    public function test_user_cannot_execute_step_when_not_assigned()
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $step      = DynaflowStep::factory()->create();

        $step->assignees()->create([
            'assignable_type' => $otherUser->getMorphClass(),
            'assignable_id'   => $otherUser->getKey(),
        ]);

        $result = $this->validator->canUserExecuteStep($step, $user);

        $this->assertFalse($result);
    }

    public function test_anyone_can_execute_step_with_no_assignees()
    {
        $user = User::factory()->create();
        $step = DynaflowStep::factory()->create();

        $result = $this->validator->canUserExecuteStep($step, $user);

        $this->assertTrue($result);
    }
}
