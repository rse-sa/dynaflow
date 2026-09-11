<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class CompletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(DynaflowEngine::class);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $assignees
     */
    protected function makeGatedStep(Dynaflow $workflow, string $policy, $assignees, DynaflowStep $target): DynaflowStep
    {
        $step = DynaflowStep::factory()->create([
            'dynaflow_id'       => $workflow->id,
            'type'              => 'approval',
            'completion_policy' => $policy,
            'order'             => 1,
        ]);

        $step->allowedTransitions()->attach($target->id);

        foreach ($assignees as $assignee) {
            $step->assignees()->create([
                'assignable_type' => $assignee->getMorphClass(),
                'assignable_id'   => $assignee->getKey(),
            ]);
        }

        return $step;
    }

    protected function makeInstance(Dynaflow $workflow, DynaflowStep $step, User $triggeredBy): DynaflowInstance
    {
        return DynaflowInstance::factory()->create([
            'dynaflow_id'       => $workflow->id,
            'current_step_id'   => $step->id,
            'triggered_by_type' => $triggeredBy->getMorphClass(),
            'triggered_by_id'   => $triggeredBy->getKey(),
        ]);
    }

    public function test_advances_any_policy_step_on_first_approval(): void
    {
        $workflow = Dynaflow::factory()->create();
        $target   = DynaflowStep::factory()->create([
            'dynaflow_id'     => $workflow->id,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);
        $users = User::factory()->count(3)->create();
        $step  = $this->makeGatedStep($workflow, 'any', $users, $target);

        $instance = $this->makeInstance($workflow, $step, $users[0]);

        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $target,
            user: $users[0],
            decision: 'approved'
        );

        $instance->refresh();
        $this->assertEquals($target->id, $instance->current_step_id);
        $this->assertEquals('completed', $instance->status);
    }

    public function test_holds_all_policy_step_until_every_assignee_acts(): void
    {
        $workflow = Dynaflow::factory()->create();
        $target   = DynaflowStep::factory()->create([
            'dynaflow_id'     => $workflow->id,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);
        $users = User::factory()->count(3)->create();
        $step  = $this->makeGatedStep($workflow, 'all', $users, $target);

        $instance = $this->makeInstance($workflow, $step, $users[0]);

        $this->engine->transitionTo(instance: $instance, targetStep: $target, user: $users[0], decision: 'approved');
        $instance->refresh();
        $this->assertEquals($step->id, $instance->current_step_id, 'should still be held after 1 of 3');
        $this->assertEquals('pending', $instance->status);

        $this->engine->transitionTo(instance: $instance, targetStep: $target, user: $users[1], decision: 'approved');
        $instance->refresh();
        $this->assertEquals($step->id, $instance->current_step_id, 'should still be held after 2 of 3');

        $this->engine->transitionTo(instance: $instance, targetStep: $target, user: $users[2], decision: 'approved');
        $instance->refresh();
        $this->assertEquals($target->id, $instance->current_step_id, 'should advance after 3 of 3');
        $this->assertEquals('completed', $instance->status);
    }

    public function test_holds_quorum_step_until_n_of_m_acts(): void
    {
        $workflow = Dynaflow::factory()->create();
        $target   = DynaflowStep::factory()->create([
            'dynaflow_id'     => $workflow->id,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);
        $users = User::factory()->count(3)->create();
        $step  = $this->makeGatedStep($workflow, 'quorum:2', $users, $target);

        $instance = $this->makeInstance($workflow, $step, $users[0]);

        $this->engine->transitionTo(instance: $instance, targetStep: $target, user: $users[0], decision: 'approved');
        $instance->refresh();
        $this->assertEquals($step->id, $instance->current_step_id, 'should still be held after 1 of quorum 2');

        $this->engine->transitionTo(instance: $instance, targetStep: $target, user: $users[1], decision: 'approved');
        $instance->refresh();
        $this->assertEquals($target->id, $instance->current_step_id, 'should advance once quorum is met');
        $this->assertEquals('completed', $instance->status);
    }
}
