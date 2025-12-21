<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowEngineTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(DynaflowEngine::class);

        // Register hooks for testing
        $this->registerTestHooks();
    }

    protected function registerTestHooks(): void
    {
        $hookManager = app(\RSE\DynaFlow\DynaflowHookManager::class);

        // Register hooks for all actions on TestModel
        $hookManager->onComplete(TestModel::class, 'create', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $snapshot = $ctx->pendingData();

            // Check if using draft records
            if (isset($snapshot['draft_model_id'])) {
                // Convert draft to real record
                $draft = TestModel::withDrafts()->find($snapshot['draft_model_id']);
                $draft->update(['is_draft' => false]);
                $ctx->instance->update(['model_id' => $draft->id]);
                $ctx->instance->setRelation('model', $draft);
            } else {
                // Create from data
                $modelData = $snapshot['data'] ?? $snapshot;
                $model = TestModel::create($modelData);
                $ctx->instance->update(['model_id' => $model->getKey()]);
                $ctx->instance->setRelation('model', $model);
            }
        });

        $hookManager->onComplete(TestModel::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $snapshot = $ctx->pendingData();
            $model    = $ctx->model();

            if (! $model) {
                return;
            }

            // Check if using draft records
            if (isset($snapshot['draft_model_id'])) {
                // Merge draft into original
                $draft = TestModel::withDrafts()->find($snapshot['draft_model_id']);
                if ($draft) {
                    $model->update($draft->only(['title', 'content']));
                    $draft->forceDelete();
                }
            } else {
                // Update from data (support both nested and direct structure)
                $modelData = $snapshot['data'] ?? $snapshot;
                $model->update($modelData);
            }
        });

        $hookManager->onComplete(TestModel::class, 'delete', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $model = $ctx->model();
            if ($model) {
                $model->delete();
            }
        });
    }

    public function test_triggers_workflow_for_model()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title'],
            user: $user
        );

        $this->assertInstanceOf(DynaflowInstance::class, $instance);
        $this->assertEquals('pending', $instance->status);
        $this->assertEquals($dynaflow->id, $instance->dynaflow_id);
        $this->assertDatabaseHas('dynaflow_data', [
            'dynaflow_instance_id' => $instance->id,
        ]);
    }

    public function test_applies_changes_directly_when_no_workflow_exists()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create(['title' => 'Old Title']);

        $result = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title'],
            user: $user
        );

        $this->assertEquals('New Title', $result->title);
        $this->assertDatabaseHas('test_models', [
            'id'    => $model->getKey(),
            'title' => 'New Title',
        ]);
    }

    public function test_cancels_duplicate_pending_workflow()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $instance1 = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'First'],
            user: $user
        );

        $instance2 = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'Second'],
            user: $user
        );

        $instance1->refresh();

        $this->assertEquals('cancelled', $instance1->status);
        $this->assertEquals('pending', $instance2->status);
    }

    public function test_executes_step_successfully()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 1]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 2]);

        $step1->allowedTransitions()->attach($step2->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'model_type'        => $model->getMorphClass(),
            'model_id'          => $model->getKey(),
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $result = $this->engine->transitionTo(
            instance: $instance,
            targetStep: $step2,
            user: $user,
            decision: 'approved',
            notes: 'Approved'
        );

        $this->assertInstanceOf(\RSE\DynaFlow\Support\DynaflowContext::class, $result);
        $instance->refresh();
        $this->assertEquals($step2->id, $instance->current_step_id);
        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step1->id,
            'decision'             => 'approved',
        ]);
    }

    public function test_completes_workflow_on_final_step()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create(['title' => 'Old Title']);

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step->allowedTransitions()->attach($step->id);
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

        $instance->dynaflowData()->create([
            'data' => [
                'action_type' => 'update',
                'timestamp' => now()->toISOString(),
                'data' => ['title' => 'New Title'],
                'original_data' => $model->toArray(),
                'changes' => [
                    'title' => [
                        'old' => $model->title,
                        'new' => 'New Title',
                        'changed' => true,
                        'type' => 'string',
                    ],
                ],
            ],
            'applied' => false,
        ]);

        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $step,
            user: $user,
            decision: 'approved'
        );

        $instance->refresh();
        $model->refresh();

        $this->assertEquals('completed', $instance->status);
        $this->assertEquals('New Title', $model->title);
        $this->assertNotNull($instance->completed_at);
    }

    public function test_cancels_workflow_on_reject()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 1]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id, 'order' => 2]);

        $step1->allowedTransitions()->attach($step2->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $this->engine->cancelWorkflow(
            instance: $instance,
            user: $user,
            decision: 'rejected'
        );

        $instance->refresh();
        $this->assertEquals('rejected', $instance->status);
    }

    public function test_throws_exception_when_user_not_authorized()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User not authorized to execute this step');

        $user             = User::factory()->create();
        $unauthorizedUser = User::factory()->create();
        $model            = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
        ]);

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $step1->allowedTransitions()->attach($step2->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $step2,
            user: $unauthorizedUser,
            decision: 'approved'
        );
    }

    public function test_throws_exception_when_invalid_transition()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid step transition');

        $user     = User::factory()->create();
        $dynaflow = Dynaflow::factory()->create();

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);
        $step3 = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $step1->allowedTransitions()->attach($step2->id);

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id'       => $dynaflow->id,
            'current_step_id'   => $step1->id,
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
        ]);

        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $step3,
            user: $user,
            decision: 'approved'
        );
    }
}
