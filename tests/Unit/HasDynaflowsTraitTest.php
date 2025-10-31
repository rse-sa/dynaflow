<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class HasDynaflowsTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_has_dynaflow_instances_relationship()
    {
        $model    = TestModel::factory()->create();
        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'model_type'  => $model->getMorphClass(),
            'model_id'    => $model->getKey(),
        ]);

        $this->assertCount(1, $model->dynaflowInstances);
        $this->assertEquals($instance->id, $model->dynaflowInstances->first()->id);
    }

    public function test_model_has_pending_dynaflows_relationship()
    {
        $model    = TestModel::factory()->create();
        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $pendingInstance = DynaflowInstance::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'model_type'  => $model->getMorphClass(),
            'model_id'    => $model->getKey(),
            'status'      => 'pending',
        ]);

        $completedInstance = DynaflowInstance::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'model_type'  => $model->getMorphClass(),
            'model_id'    => $model->getKey(),
            'status'      => 'completed',
        ]);

        $this->assertCount(1, $model->pendingDynaflows);
        $this->assertEquals($pendingInstance->id, $model->pendingDynaflows->first()->id);
    }

    public function test_model_can_get_data_with_pending_changes()
    {
        $model = TestModel::factory()->create(['title' => 'Original Title']);
        $user  = User::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'status'            => 'pending',
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $instance->dynaflowData()->create([
            'data'    => ['title' => 'Pending Title'],
            'applied' => false,
        ]);

        $dataWithChanges = $model->getWithPendingChanges();

        $this->assertEquals('Pending Title', $dataWithChanges['title']);
        $this->assertEquals('Original Title', $model->title);
    }

    public function test_model_returns_original_data_when_no_pending_changes()
    {
        $model = TestModel::factory()->create(['title' => 'Original Title']);

        $dataWithChanges = $model->getWithPendingChanges();

        $this->assertEquals('Original Title', $dataWithChanges['title']);
    }

    public function test_model_has_pending_dynaflow_check()
    {
        $model = TestModel::factory()->create();
        $user  = User::factory()->create();

        $this->assertFalse($model->hasPendingDynaflow());

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'status'            => 'pending',
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $this->assertTrue($model->hasPendingDynaflow());
    }
}
