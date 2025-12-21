<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Services\DynaflowStepVisualizer;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowStepVisualizerTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowStepVisualizer $visualizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visualizer = app(DynaflowStepVisualizer::class);
    }

    public function test_generates_visualization_data()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'name'   => ['en' => 'Test Workflow'],
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'name'        => ['en' => 'Step 1'],
            'order'       => 1,
        ]);

        $step2 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'name'        => ['en' => 'Step 2'],
            'order'       => 2,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $data = $this->visualizer->generate($instance);

        $this->assertArrayHasKey('instance', $data);
        $this->assertArrayHasKey('dynaflow', $data);
        $this->assertArrayHasKey('steps', $data);

        $this->assertEquals($instance->id, $data['instance']['id']);
        $this->assertEquals('Test Workflow', $data['dynaflow']['name']);
        $this->assertCount(2, $data['steps']);

        $this->assertTrue($data['steps'][0]['is_current']);
        $this->assertFalse($data['steps'][0]['is_completed']);
        $this->assertFalse($data['steps'][1]['is_current']);
    }

    public function test_includes_execution_data_for_completed_steps()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $execution = DynaflowStepExecution::create([
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step->id,
            'executed_by_type'     => $user->getMorphClass(),
            'executed_by_id'       => $user->getKey(),
            'decision'             => 'approve',
            'note'                 => 'Test note',
            'duration'       => 24 * 60,
            'executed_at'          => now(),
        ]);

        $data = $this->visualizer->generate($instance);

        $this->assertTrue($data['steps'][0]['is_completed']);
        $this->assertNotNull($data['steps'][0]['execution']);
        $this->assertEquals('approve', $data['steps'][0]['execution']['decision']);
        $this->assertEquals('Test note', $data['steps'][0]['execution']['note']);
        $this->assertEquals(24, $data['steps'][0]['execution']['duration_hours']);
        $this->assertEquals(1.0, $data['steps'][0]['execution']['duration_days']);
    }

    public function test_includes_transitions_data()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 1]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 2]);
        $step3 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 3]);

        $step1->allowedTransitions()->attach([$step2->id, $step3->id]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $data = $this->visualizer->generate($instance);

        $this->assertCount(2, $data['steps'][0]['transitions']);
        $this->assertEquals($step2->id, $data['steps'][0]['transitions'][0]['id']);
        $this->assertEquals($step3->id, $data['steps'][0]['transitions'][1]['id']);
    }

    public function test_includes_assignees_data()
    {
        $user  = User::factory()->create(['name' => 'John Doe']);
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $step->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'current_step_id'   => $step->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $data = $this->visualizer->generate($instance);

        $this->assertCount(1, $data['steps'][0]['assignees']);
        $this->assertEquals('John Doe', $data['steps'][0]['assignees'][0]['name']);
        $this->assertEquals('User', $data['steps'][0]['assignees'][0]['type']);
    }
}
