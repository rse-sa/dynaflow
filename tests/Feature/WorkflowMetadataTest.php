<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class WorkflowMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(DynaflowEngine::class);
    }

    public function test_metadata_is_stored_in_database_when_triggering_workflow()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
        ]);

        $metadata = [
            'priority'     => 'high',
            'source'       => 'api',
            'reference_id' => 'REF-123',
            'tags'         => ['urgent', 'legal-review'],
        ];

        $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        $instance = DynaflowInstance::where('dynaflow_id', $dynaflow->id)->first();
        $this->assertNotNull($instance);
        $this->assertEquals($metadata, $instance->metadata);
        $this->assertEquals('high', $instance->metadata['priority']);
    }

    public function test_metadata_accessible_via_direct_parameter_injection()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        $metadata = [
            'priority'     => 'high',
            'source'       => 'web',
            'custom_field' => 'custom_value',
        ];

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertEquals($metadata, $capturedMetadata);
        $this->assertEquals('high', $capturedMetadata['priority']);
        $this->assertEquals('web', $capturedMetadata['source']);
    }

    public function test_metadata_accessible_via_context_method()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedViaMeta = null;
        $capturedAllMeta = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function ($ctx) use (&$capturedViaMeta, &$capturedAllMeta) {
                $capturedViaMeta = $ctx->meta('priority');
                $capturedAllMeta = $ctx->meta();
            });

        $metadata = [
            'priority'     => 'urgent',
            'source'       => 'api',
            'reference_id' => 'REF-456',
        ];

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertEquals('urgent', $capturedViaMeta);
        $this->assertEquals($metadata, $capturedAllMeta);
    }

    public function test_metadata_returns_null_for_non_existent_key()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedValue = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function ($ctx) use (&$capturedValue) {
                $capturedValue = $ctx->meta('non_existent_key');
            });

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: ['existing_key' => 'value']
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertNull($capturedValue);
    }

    public function test_empty_metadata_defaults_to_empty_array()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        // Trigger without metadata parameter
        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertIsArray($capturedMetadata);
        $this->assertEmpty($capturedMetadata);
    }

    public function test_metadata_works_with_mixed_parameters()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedModel    = null;
        $capturedMetadata = null;
        $capturedUser     = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (
                TestModel $model,
                array $metadata,
                $user
            ) use (&$capturedModel, &$capturedMetadata, &$capturedUser) {
                $capturedModel    = $model;
                $capturedMetadata = $metadata;
                $capturedUser     = $user;
            });

        $metadata = ['priority' => 'high'];

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertSame($model->id, $capturedModel->id);
        $this->assertEquals($metadata, $capturedMetadata);
        $this->assertSame($user->id, $capturedUser->id);
    }

    public function test_metadata_with_bypass_mode_direct_complete()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->directComplete()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        // Add exception for this user
        \RSE\DynaFlow\Models\DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
        ]);

        DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'final',
            'is_final'    => true,
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        $metadata = ['bypassed' => true, 'reason' => 'admin_exception'];

        $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        $this->assertEquals($metadata, $capturedMetadata);

        $instance = DynaflowInstance::where('dynaflow_id', $dynaflow->id)->first();
        $this->assertNotNull($instance);
        $this->assertEquals($metadata, $instance->metadata);
    }

    public function test_metadata_with_bypass_mode_auto_follow()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->autoFollow()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'final',
            'order'       => 2,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);

        // Add exception for this user
        \RSE\DynaFlow\Models\DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        $metadata = ['bypass_mode' => 'auto_follow', 'auto_approved' => true];

        $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        $this->assertEquals($metadata, $capturedMetadata);
    }

    public function test_metadata_with_bypass_mode_custom_steps()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->customSteps(['step1', 'final'])->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'final',
            'order'       => 2,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);

        // Add exception for this user
        \RSE\DynaFlow\Models\DynaflowException::create([
            'dynaflow_id'        => $dynaflow->id,
            'exceptionable_type' => $user->getMorphClass(),
            'exceptionable_id'   => $user->getKey(),
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        $metadata = ['custom_steps' => true, 'step1' => 'auto_approved'];

        $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $metadata
        );

        $this->assertEquals($metadata, $capturedMetadata);
    }

    public function test_metadata_accessible_in_transition_hooks()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $step2 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step2',
            'order'       => 2,
            // NOT final - so afterTransitionTo hook will be called
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'final',
            'order'       => 3,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);
        $step2->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $hookWasCalled    = false;
        $capturedMetadata = null;

        // Get hook manager and register directly
        $hookManager = app(\RSE\DynaFlow\DynaflowHookManager::class);
        $hookManager->afterTransitionTo('*', function ($ctx) use (&$hookWasCalled, &$capturedMetadata) {
            $hookWasCalled    = true;
            $capturedMetadata = $ctx->meta('priority');
        });

        // Trigger workflow with metadata
        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: ['priority' => 'critical']
        );

        // Verify metadata was stored
        $instance->refresh();
        $this->assertEquals(['priority' => 'critical'], $instance->metadata);

        // Transition to non-final step - afterTransitionTo hook should be called
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $step2,
            user: $user,
            decision: 'approved'
        );

        $this->assertTrue($hookWasCalled, 'afterTransitionTo hook should have been called');
        $this->assertEquals('critical', $capturedMetadata);
    }

    public function test_metadata_preserves_nested_arrays_and_objects()
    {
        $user  = User::factory()->create();
        $model = TestModel::factory()->create();

        $dynaflow = Dynaflow::factory()->create([
            'topic'  => $model->getMorphClass(),
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $dynaflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $finalStep = DynaflowStep::factory()->create([
            'dynaflow_id'     => $dynaflow->id,
            'key'             => 'final',
            'order'           => 2,
            'is_final'        => true,
            'workflow_status' => 'completed',
        ]);

        $step1->allowedTransitions()->attach($finalStep->id);
        $step1->assignees()->create([
            'assignable_type' => $user->getMorphClass(),
            'assignable_id'   => $user->getKey(),
        ]);

        $capturedMetadata = null;

        DynaflowFacade::forWorkflow($model->getMorphClass(), 'update')
            ->whenCompleted()
            ->execute(function (array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
            });

        $complexMetadata = [
            'priority' => 'high',
            'tags'     => ['urgent', 'legal', 'executive'],
            'details'  => [
                'department' => 'finance',
                'region'     => 'emea',
                'amount'     => 50000,
            ],
            'flags' => [
                'requires_approval' => true,
                'automatic'         => false,
            ],
        ];

        $instance = $this->engine->trigger(
            topic: $model->getMorphClass(),
            action: 'update',
            model: $model,
            data: ['title' => 'New Title', '_marker' => 1],
            user: $user,
            metadata: $complexMetadata
        );

        // Transition to final step to trigger completion hook
        $this->engine->transitionTo(
            instance: $instance,
            targetStep: $finalStep,
            user: $user,
            decision: 'approved'
        );

        $this->assertEquals($complexMetadata, $capturedMetadata);
        $this->assertEquals(['urgent', 'legal', 'executive'], $capturedMetadata['tags']);
        $this->assertEquals('finance', $capturedMetadata['details']['department']);
        $this->assertTrue($capturedMetadata['flags']['requires_approval']);
    }
}
