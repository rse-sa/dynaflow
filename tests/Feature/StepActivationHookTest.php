<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

/**
 * Tests for the onStepActivated hook calling transitionTo() pattern.
 *
 * Before the fix, calling transitionTo() from an onStepActivated hook caused:
 *   - Stale Eloquent relationship cache on $instance->currentStep
 *   - "Invalid step transition (X -> Y)" errors in subsequent transitions
 *   - Double-execution of steps when the engine tried to skip/auto-execute
 *     a step that had already been advanced by the hook
 */
class StepActivationHookTest extends TestCase
{
    use RefreshDatabase;

    protected DynaflowEngine $engine;

    protected DynaflowHookManager $hookManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine      = app(DynaflowEngine::class);
        $this->hookManager = app(DynaflowHookManager::class);

        // Prevent hooks registered in one test from leaking into subsequent tests
        // (DynaflowHookManager is a singleton; RefreshDatabase only resets the DB).
        $this->hookManager->reset();
    }

    /**
     * Build a linear workflow with N steps.
     * Steps are given deterministic keys ("step_1", "step_2", …) so that
     * onStepActivatedFor() can reference them by key.
     *
     * @return DynaflowStep[] [$step1, $step2, ..., $stepN]
     */
    private function buildLinearWorkflow(User $user, int $stepCount = 3): array
    {
        $workflow = Dynaflow::factory()->create([
            'topic'  => TestModel::class,
            'action' => 'update',
            'active' => true,
        ]);

        $steps = [];
        for ($i = 1; $i <= $stepCount; $i++) {
            $step = DynaflowStep::factory()->create([
                'dynaflow_id' => $workflow->id,
                'key'         => "step_{$i}",
                'order'       => $i,
                'is_final'    => $i === $stepCount,
            ]);
            $steps[] = $step;

            if ($i > 1) {
                $steps[$i - 2]->allowedTransitions()->attach($step->id);
            }

            // Assign the user to every step
            $step->assignees()->create([
                'assignable_type' => $user->getMorphClass(),
                'assignable_id'   => $user->getKey(),
            ]);
        }

        return $steps;
    }

    /**
     * When a hook calls transitionTo() from inside onStepActivated,
     * the engine should NOT double-execute the step.
     * Exactly ONE execution record should exist per step.
     */
    public function test_hook_calling_transition_to_does_not_double_execute(): void
    {
        $user  = User::factory()->create();
        $steps = $this->buildLinearWorkflow($user, 3);
        [$step1, $step2, $step3] = $steps;

        $workflow = $step1->dynaflow;
        $model    = TestModel::factory()->create();

        // When step2 activates, hook auto-transitions to step3 (simulates "no assignees" skip)
        $this->hookManager->onStepActivatedFor(
            topic: TestModel::class,
            action: 'update',
            stepIdentifier: $step2->key,
            callback: function (DynaflowInstance $instance, DynaflowStep $step) use ($step3, $user) {
                $this->engine->transitionTo($instance, $step3, $user, 'auto_skip');
            }
        );

        // Trigger workflow → instance at step1
        // Pass non-empty data so the field-filter doesn't short-circuit trigger().
        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);

        // Manually transition step1 → step2 (the hook will fire and advance to step3)
        $this->engine->transitionTo($instance->fresh(), $step2, $user, 'approved');

        // step1 and step2 should each have exactly ONE execution record
        $this->assertDatabaseCount('dynaflow_step_executions', 2);

        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step1->id,
            'decision'             => 'approved',
        ]);

        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step2->id,
            'decision'             => 'auto_skip',
        ]);

        // Instance should be at step3 (the final step — completed)
        $instance->refresh();
        $this->assertEquals('auto_skip', $instance->status);
    }

    /**
     * When the first step has no assignees and a hook auto-skips it,
     * the trigger() should not throw "Invalid step transition" and
     * the instance should land at the correct step.
     */
    public function test_hook_skips_first_step_at_trigger_time(): void
    {
        $user  = User::factory()->create();
        $steps = $this->buildLinearWorkflow($user, 3);
        [$step1, $step2, $step3] = $steps;

        $model = TestModel::factory()->create();

        // Hook: when step1 activates with no assignees, skip to step2
        $this->hookManager->onStepActivatedFor(
            topic: TestModel::class,
            action: 'update',
            stepIdentifier: $step1->key,
            callback: function (DynaflowInstance $instance, DynaflowStep $step) use ($step2, $user) {
                $this->engine->transitionTo($instance, $step2, $user, 'auto_skip');
            }
        );

        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);

        // Instance should be at step2, not step1
        $this->assertEquals($step2->id, $instance->current_step_id);

        // One execution record for step1 (the auto-skip)
        $this->assertDatabaseCount('dynaflow_step_executions', 1);
        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step1->id,
            'decision'             => 'auto_skip',
        ]);
    }

    /**
     * Multi-step auto-skip chain: hooks skip steps 1 and 2, landing on step 3.
     * Verifies no "Invalid step transition" errors even with chained hook transitions.
     */
    public function test_chained_hook_skips_multiple_steps(): void
    {
        $user  = User::factory()->create();
        $steps = $this->buildLinearWorkflow($user, 4);
        [$step1, $step2, $step3, $step4] = $steps;

        $model = TestModel::factory()->create();

        // Both step1 and step2 are auto-skipped by hooks
        $this->hookManager->onStepActivatedFor(
            topic: TestModel::class,
            action: 'update',
            stepIdentifier: $step1->key,
            callback: function (DynaflowInstance $instance) use ($step2, $user) {
                $this->engine->transitionTo($instance, $step2, $user, 'auto_skip');
            }
        );

        $this->hookManager->onStepActivatedFor(
            topic: TestModel::class,
            action: 'update',
            stepIdentifier: $step2->key,
            callback: function (DynaflowInstance $instance) use ($step3, $user) {
                $this->engine->transitionTo($instance, $step3, $user, 'auto_skip');
            }
        );

        // Should not throw "Invalid step transition"
        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);

        // Instance lands at step3 (after chained skips of step1 and step2)
        $this->assertEquals($step3->id, $instance->current_step_id);

        // Two execution records (one per skipped step)
        $this->assertDatabaseCount('dynaflow_step_executions', 2);
    }

    /**
     * An inactive step (active = false) combined with a hook-based transition
     * on the next step must not produce a stale-cache conflict.
     *
     * Workflow: step1(active) → step2(inactive) → step3(active, hook skips) → step4(final)
     */
    public function test_inactive_step_followed_by_hook_skip_does_not_conflict(): void
    {
        $user     = User::factory()->create();
        $workflow = Dynaflow::factory()->create([
            'topic'  => TestModel::class,
            'action' => 'update',
            'active' => true,
        ]);

        $step1 = DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id, 'key' => 'step_1', 'order' => 1, 'active' => true]);
        $step2 = DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id, 'key' => 'step_2', 'order' => 2, 'active' => false]);
        $step3 = DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id, 'key' => 'step_3', 'order' => 3, 'active' => true]);
        $step4 = DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id, 'key' => 'step_4', 'order' => 4, 'active' => true, 'is_final' => true, 'workflow_status' => 'completed']);

        $step1->allowedTransitions()->attach($step2->id);
        $step2->allowedTransitions()->attach($step3->id);
        $step3->allowedTransitions()->attach($step4->id);

        foreach ([$step1, $step3, $step4] as $step) {
            $step->assignees()->create([
                'assignable_type' => $user->getMorphClass(),
                'assignable_id'   => $user->getKey(),
            ]);
        }

        $model = TestModel::factory()->create();

        // Hook: when step3 activates, auto-skip to step4 (the final step)
        $this->hookManager->onStepActivatedFor(
            topic: TestModel::class,
            action: 'update',
            stepIdentifier: $step3->key,
            callback: function (DynaflowInstance $instance) use ($step4, $user) {
                $this->engine->transitionTo($instance, $step4, $user, 'auto_skip');
            }
        );

        // Trigger: instance starts at step1 (active), step2 auto-skipped, step3 hook-skipped to step4
        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);

        // step1 is the first active step — instance should be at step1 initially
        $this->assertEquals($step1->id, $instance->current_step_id);

        // No executions yet (step1 is waiting for human input)
        $this->assertDatabaseCount('dynaflow_step_executions', 0);

        // Transition step1 → step2 (engine auto-skips step2, then hook skips step3 → step4 completing workflow)
        $this->engine->transitionTo($instance->fresh(), $step2, $user, 'approved');

        // Executions: step1(approved), step2(skipped by engine), step3(auto_skip by hook)
        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step1->id,
            'decision'             => 'approved',
        ]);

        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step2->id,
            'decision'             => 'skipped',
            'bypassed'             => true,
        ]);

        $this->assertDatabaseHas('dynaflow_step_executions', [
            'dynaflow_instance_id' => $instance->id,
            'dynaflow_step_id'     => $step3->id,
            'decision'             => 'auto_skip',
        ]);

        // Workflow should be completed (step4 is final)
        $instance->refresh();
        $this->assertTrue($instance->isCompleted());
    }

    /**
     * When DYNAFLOW_DEBUG is enabled, the log channel should receive entries
     * for all key workflow events.
     */
    public function test_debug_logging_writes_entries_when_enabled(): void
    {
        config(['dynaflow.debug' => true]);
        Log::spy();

        $user  = User::factory()->create();
        $steps = $this->buildLinearWorkflow($user, 2);
        [$step1, $step2] = $steps;

        $this->hookManager->onComplete(TestModel::class, 'update', function () {});

        $model = TestModel::factory()->create();

        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);

        // Should have logged trigger and instance creation
        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains($msg, '[Dynaflow] Trigger requested'))
            ->once();

        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains($msg, '[Dynaflow] Instance created'))
            ->once();

        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains($msg, '[Dynaflow] Step activated'))
            ->once();

        // Now transition
        $this->engine->transitionTo($instance->fresh(), $step2, $user, 'approved');

        // Transition and completion should also be logged
        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains($msg, '[Dynaflow] Transition requested'))
            ->once();

        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains($msg, '[Dynaflow] Workflow completed'))
            ->once();
    }

    /**
     * When DYNAFLOW_DEBUG is disabled (the default), no Dynaflow entries are written.
     */
    public function test_debug_logging_silent_when_disabled(): void
    {
        config(['dynaflow.debug' => false]);
        Log::spy();

        $user  = User::factory()->create();
        $steps = $this->buildLinearWorkflow($user, 2);
        [$step1, $step2] = $steps;

        $this->hookManager->onComplete(TestModel::class, 'update', function () {});

        $model = TestModel::factory()->create();

        $instance = $this->engine->trigger(TestModel::class, 'update', $model, ['_marker' => 1], $user);
        $this->engine->transitionTo($instance->fresh(), $step2, $user, 'approved');

        // shouldNotHaveReceived() in Mockery 1.x verifies immediately and returns null,
        // so we cannot chain ->withArgs(). Since debug=false the logger never calls
        // the spy at all, so asserting no debug() calls were made is sufficient.
        Log::shouldNotHaveReceived('debug');
    }
}
