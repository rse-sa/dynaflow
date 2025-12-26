<?php

namespace RSE\DynaFlow\Tests\Unit;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RSE\DynaFlow\Enums\BypassMode;
use RSE\DynaFlow\Events\DynaflowCompleted;
use RSE\DynaFlow\Events\DynaflowStarted;
use RSE\DynaFlow\Facades\Dynaflow;
use RSE\DynaFlow\Models\Dynaflow as DynaflowModel;
use RSE\DynaFlow\Models\DynaflowException;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Services\DynaflowValidator;
use RSE\DynaFlow\Support\DynaflowContext;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class BypassModesTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowEngine $engine;

    protected User $user;

    protected User $bypassUser;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine     = app(DynaflowEngine::class);
        $this->user       = User::factory()->create();
        $this->bypassUser = User::factory()->create(['email' => 'bypass@example.com']);
        $this->post       = Post::create(['title' => 'Test Post', 'content' => 'Test Content']);
    }

    /**
     * @throws \Throwable
     */
    public function test_manual_mode_uses_apply_directly_and_creates_no_instance()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::MANUAL->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookExecuted = false;
        Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) use (&$hookExecuted) {
            $hookExecuted = true;
        });

        $result = $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated Title'],
            $this->bypassUser
        );

        $this->assertTrue($hookExecuted);
        $this->assertInstanceOf(Post::class, $result);
        $this->assertDatabaseCount('dynaflow_instances', 0);
        $this->assertDatabaseCount('dynaflow_step_executions', 0);
    }

    /**
     * @throws \Throwable
     */
    public function test_direct_complete_creates_instance_and_jumps_to_final_step()
    {
        Event::fake([DynaflowStarted::class, DynaflowCompleted::class]);

        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookExecuted = false;
        Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) use (&$hookExecuted) {
            $hookExecuted = true;
            $this->assertTrue($ctx->isBypassed());
            $this->assertEquals('auto_approved', $ctx->decision);
        });

        $result = $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated Title'],
            $this->bypassUser
        );

        $this->assertTrue($hookExecuted);
        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals('auto_approved', $result->status);
        $this->assertDatabaseCount('dynaflow_instances', 1);
        $this->assertDatabaseCount('dynaflow_step_executions', 1);

        $execution = DynaflowStepExecution::first();
        $this->assertTrue($execution->bypassed);
        $this->assertEquals('auto_approved', $execution->decision);
        $this->assertEquals(0, $execution->duration);

        Event::assertDispatched(DynaflowStarted::class);
        Event::assertDispatched(DynaflowCompleted::class);
    }

    /**
     * @throws \Throwable
     */
    public function test_direct_complete_throws_exception_when_no_final_step()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('has no final step');

        $workflow = DynaflowModel::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        // Create step WITHOUT is_final flag
        DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'step1',
            'is_final'    => false,
        ]);

        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();
        $this->createBypassException($workflow, $this->bypassUser);

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_auto_follow_creates_execution_for_all_steps()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::AUTO_FOLLOW->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookExecuted = false;
        Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) use (&$hookExecuted) {
            $hookExecuted = true;
            $this->assertTrue($ctx->isBypassed());
        });

        $result = $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated Title'],
            $this->bypassUser
        );

        $this->assertTrue($hookExecuted);
        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals('auto_approved', $result->status);

        // Should have 3 executions (step1, step2, final)
        $this->assertDatabaseCount('dynaflow_step_executions', 3);

        $executions = DynaflowStepExecution::all();
        foreach ($executions as $execution) {
            $this->assertTrue($execution->bypassed);
            $this->assertEquals('auto_approved', $execution->decision);
        }
    }

    /**
     * @throws \Throwable
     */
    public function test_auto_follow_throws_exception_when_workflow_has_branching()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('has branching paths');

        $workflow = DynaflowModel::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'review',
            'order'       => 1,
        ]);

        $step2a = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'approved',
            'order'       => 2,
            'is_final'    => true,
        ]);

        $step2b = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'rejected',
            'order'       => 3,
            'is_final'    => true,
        ]);

        // Create branching - step1 has two allowed transitions
        $step1->allowedTransitions()->attach([$step2a->id, $step2b->id]);

        $workflow->setBypassMode(BypassMode::AUTO_FOLLOW->value)->save();
        $this->createBypassException($workflow, $this->bypassUser);

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_custom_steps_follows_specified_steps()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::CUSTOM_STEPS->value, ['step2', 'final'])->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookExecuted = false;
        Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) use (&$hookExecuted) {
            $hookExecuted = true;
            $this->assertTrue($ctx->isBypassed());
        });

        $result = $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated Title'],
            $this->bypassUser
        );

        $this->assertTrue($hookExecuted);
        $this->assertInstanceOf(DynaflowInstance::class, $result);

        // Should have 2 executions (step2, final) - skipped step1
        $this->assertDatabaseCount('dynaflow_step_executions', 2);

        $stepKeys = DynaflowStepExecution::join('dynaflow_steps', 'dynaflow_step_executions.dynaflow_step_id', '=', 'dynaflow_steps.id')
            ->pluck('dynaflow_steps.key')
            ->toArray();

        $this->assertEquals(['step2', 'final'], $stepKeys);
    }

    /**
     * @throws \Throwable
     */
    public function test_custom_steps_throws_exception_when_step_not_found()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not found in workflow');

        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::CUSTOM_STEPS->value, ['nonexistent_step', 'final'])->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_custom_steps_throws_exception_when_last_step_is_not_final()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must be a final step');

        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::CUSTOM_STEPS->value, ['step1', 'step2'])->save(); // step2 is not final

        $this->createBypassException($workflow, $this->bypassUser);

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_custom_steps_throws_exception_when_steps_array_empty()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no steps defined');

        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::CUSTOM_STEPS->value, [])->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_before_transition_to_hooks_run_during_bypass()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookCalled = false;
        Dynaflow::beforeTransitionTo('final', function (DynaflowContext $ctx) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertTrue($ctx->isBypassed());
        });

        Dynaflow::onComplete(Post::class, 'update', function () {});

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );

        $this->assertTrue($hookCalled);
    }

    /**
     * @throws \Throwable
     */
    public function test_before_transition_to_hooks_can_block_bypass()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('blocked by beforeTransitionTo hook');

        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        Dynaflow::beforeTransitionTo('final', function (DynaflowContext $ctx) {
            return false; // Block bypass
        });

        Dynaflow::onComplete(Post::class, 'update', function () {});

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    /**
     * @throws \Throwable
     */
    public function test_after_transition_to_hooks_run_during_bypass()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookCalled = false;
        Dynaflow::afterTransitionTo('final', function (DynaflowContext $ctx) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertTrue($ctx->isBypassed());
        });

        Dynaflow::onComplete(Post::class, 'update', function () {});

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );

        $this->assertTrue($hookCalled);
    }

    /**
     * @throws \Throwable
     */
    public function test_on_transition_hooks_run_during_auto_follow()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::AUTO_FOLLOW->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        $hookCalled = false;
        Dynaflow::onTransition('step1', 'step2', function (DynaflowContext $ctx) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertTrue($ctx->isBypassed());
        });

        Dynaflow::onComplete(Post::class, 'update', function () {});

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );

        $this->assertTrue($hookCalled);
    }

    public function test_will_bypass_helper_returns_true_when_user_has_exception()
    {
        $workflow = $this->createLinearWorkflow();
        $this->createBypassException($workflow, $this->bypassUser);

        $result = Dynaflow::willBypass(Post::class, 'update', $this->bypassUser);

        $this->assertTrue($result);
    }

    public function test_will_bypass_helper_returns_false_when_user_has_no_exception()
    {
        $this->createLinearWorkflow();

        $result = Dynaflow::willBypass(Post::class, 'update', $this->user);

        $this->assertFalse($result);
    }

    public function test_will_bypass_helper_returns_false_when_no_workflow_exists()
    {
        $result = Dynaflow::willBypass(Post::class, 'nonexistent', $this->user);

        $this->assertFalse($result);
    }

    public function test_is_linear_returns_true_for_linear_workflow()
    {
        $workflow = $this->createLinearWorkflow();

        $this->assertTrue($workflow->isLinear());
    }

    public function test_is_linear_returns_false_for_branching_workflow()
    {
        $workflow = DynaflowModel::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'order'       => 1,
        ]);

        $step2a = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'order'       => 2,
            'is_final'    => true,
        ]);

        $step2b = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'order'       => 3,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach([$step2a->id, $step2b->id]);

        $this->assertFalse($workflow->fresh()->isLinear());
    }

    public function test_factory_helpers_create_workflows_with_bypass_modes()
    {
        $directComplete = DynaflowModel::factory()->directComplete()->create();
        $this->assertEquals(BypassMode::DIRECT_COMPLETE->value, $directComplete->getBypassMode());

        $autoFollow = DynaflowModel::factory()->autoFollow()->create();
        $this->assertEquals(BypassMode::AUTO_FOLLOW->value, $autoFollow->getBypassMode());

        $customSteps = DynaflowModel::factory()->customSteps(['step1', 'step2'])->create();
        $this->assertEquals(BypassMode::CUSTOM_STEPS->value, $customSteps->getBypassMode());
        $this->assertEquals(['step1', 'step2'], $customSteps->getBypassSteps());
    }

    /**
     * @throws \Throwable
     */
    public function test_duplicate_instances_are_cancelled_during_bypass()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        // Create existing pending instance
        $existingInstance = DynaflowInstance::factory()->create([
            'dynaflow_id' => $workflow->id,
            'model_type'  => Post::class,
            'model_id'    => $this->post->id,
            'status'      => 'pending',
        ]);

        $this->createBypassException($workflow, $this->bypassUser);

        Dynaflow::onComplete(Post::class, 'update', function () {});

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );

        $this->assertEquals('cancelled', $existingInstance->fresh()->status);
    }

    /**
     * @throws \Throwable
     */
    public function test_context_is_bypassed_flag_is_set_correctly()
    {
        $workflow = $this->createLinearWorkflow();
        $workflow->setBypassMode(BypassMode::DIRECT_COMPLETE->value)->save();

        $this->createBypassException($workflow, $this->bypassUser);

        Dynaflow::onComplete(Post::class, 'update', function (DynaflowContext $ctx) {
            $this->assertTrue($ctx->isBypassed);
            $this->assertTrue($ctx->isBypassed());
        });

        $this->engine->trigger(
            Post::class,
            'update',
            $this->post,
            ['title' => 'Updated'],
            $this->bypassUser
        );
    }

    public function test_per_workflow_authorization_takes_precedence()
    {
        $workflow = $this->createLinearWorkflow();
        $step     = $workflow->steps()->first();

        // Global authorization - would deny
        Dynaflow::authorizeStepUsing(function () {
            return false;
        });

        // Per-workflow authorization - allows
        Dynaflow::authorizeWorkflowStepUsing(Post::class, 'update', function () {
            return true;
        });

        $instance = DynaflowInstance::factory()->create([
            'dynaflow_id' => $workflow->id,
        ]);

        $validator  = app(DynaflowValidator::class);
        $canExecute = $validator->canUserExecuteStep($step, $this->user, $instance);

        $this->assertTrue($canExecute);
    }

    // Helper methods

    protected function createLinearWorkflow(): DynaflowModel
    {
        $workflow = DynaflowModel::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'step1',
            'order'       => 1,
        ]);

        $step2 = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'step2',
            'order'       => 2,
        ]);

        $final = DynaflowStep::factory()->create([
            'dynaflow_id' => $workflow->id,
            'key'         => 'final',
            'order'       => 3,
            'is_final'    => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);
        $step2->allowedTransitions()->attach($final->id);

        return $workflow;
    }

    protected function createBypassException(DynaflowModel $workflow, User $user): DynaflowException
    {
        return DynaflowException::create([
            'dynaflow_id'        => $workflow->id,
            'exceptionable_type' => User::class,
            'exceptionable_id'   => $user->id,
        ]);
    }
}
