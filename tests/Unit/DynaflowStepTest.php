<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_dynaflow_step()
    {
        $dynaflow = Dynaflow::factory()->create();

        $step = DynaflowStep::create([
            'dynaflow_id' => $dynaflow->id,
            'name'        => ['en' => 'Review Step'],
            'description' => ['en' => 'Review description'],
            'order'       => 1,
            'is_final'    => false,
        ]);

        $this->assertDatabaseHas('dynaflow_steps', [
            'dynaflow_id' => $dynaflow->id,
            'order'       => 1,
            'is_final'    => false,
        ]);
    }

    public function test_step_has_allowed_transitions()
    {
        $dynaflow = Dynaflow::factory()->create();
        $step1    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 1]);
        $step2    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 2]);
        $step3    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 3]);

        $step1->allowedTransitions()->attach([$step2->id, $step3->id]);

        $this->assertCount(2, $step1->allowedTransitions);
        $this->assertTrue($step1->canTransitionTo($step2));
        $this->assertTrue($step1->canTransitionTo($step3));
    }

    public function test_step_cannot_transition_to_invalid_step()
    {
        $dynaflow = Dynaflow::factory()->create();
        $step1    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);
        $step2    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);
        $step3    = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $step1->allowedTransitions()->attach($step2->id);

        $this->assertTrue($step1->canTransitionTo($step2));
        $this->assertFalse($step1->canTransitionTo($step3));
    }

    public function test_step_has_assignees()
    {
        $step = DynaflowStep::factory()->create();
        $user = User::factory()->create();

        $step->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $this->assertCount(1, $step->assignees);
        $this->assertEquals($user->getKey(), $step->assignees->first()->assignable_id);
    }
}
