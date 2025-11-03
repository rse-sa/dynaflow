<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class BeforeTriggerHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_workflow_when_before_trigger_hook_returns_false(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
            'view_count' => 0,
        ]);

        $workflow = Dynaflow::create([
            'name' => ['en' => 'Post Update Workflow'],
            'topic' => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register beforeTrigger hook that checks for specific field
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
            // Skip workflow if only view_count changed
            $originalData = $model->only(array_keys($data));
            $changedFields = array_keys(array_diff_assoc($data, $originalData));

            return ! (count($changedFields) === 1 && in_array('view_count', $changedFields));
        });

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });

        $engine = app(DynaflowEngine::class);

        // Change only view_count - hook should return false, skip workflow
        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['view_count' => 10],
            $user
        );

        $this->assertInstanceOf(Post::class, $result);
        $this->assertEquals(10, $result->view_count);
    }

    public function test_continues_workflow_when_before_trigger_hook_returns_true(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
            'view_count' => 0,
        ]);

        $workflow = Dynaflow::create([
            'name' => ['en' => 'Post Update Workflow'],
            'topic' => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register beforeTrigger hook that checks for specific field
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) {
            // Skip workflow if only view_count changed
            $originalData = $model->only(array_keys($data));
            $changedFields = array_keys(array_diff_assoc($data, $originalData));

            return ! (count($changedFields) === 1 && in_array('view_count', $changedFields));
        });

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });

        $engine = app(DynaflowEngine::class);

        // Change title - hook should return true, continue workflow
        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['title' => 'New Title'],
            $user
        );

        $this->assertInstanceOf(\RSE\DynaFlow\Models\DynaflowInstance::class, $result);
        $this->assertEquals('pending', $result->status);
    }

    public function test_supports_wildcard_before_trigger_hooks(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
        ]);

        $workflow = Dynaflow::create([
            'name' => ['en' => 'Post Update Workflow'],
            'topic' => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        $hookCalled = false;

        // Register wildcard hook - applies to all topic/action combinations
        DynaflowFacade::beforeTrigger('*', '*', function ($workflow, $model, $data, $user) use (&$hookCalled) {
            $hookCalled = true;

            return true;
        });

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });

        $engine = app(DynaflowEngine::class);

        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['title' => 'New Title'],
            $user
        );

        $this->assertTrue($hookCalled);
        $this->assertInstanceOf(\RSE\DynaFlow\Models\DynaflowInstance::class, $result);
    }

    public function test_executes_multiple_before_trigger_hooks_in_order(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
        ]);

        $workflow = Dynaflow::create([
            'name' => ['en' => 'Post Update Workflow'],
            'topic' => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        $executionOrder = [];

        // Register multiple hooks
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) use (&$executionOrder) {
            $executionOrder[] = 'first';

            return true;
        });

        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) use (&$executionOrder) {
            $executionOrder[] = 'second';

            return true;
        });

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });

        $engine = app(DynaflowEngine::class);

        $engine->trigger(
            Post::class,
            'update',
            $post,
            ['title' => 'New Title'],
            $user
        );

        $this->assertEquals(['first', 'second'], $executionOrder);
    }

    public function test_stops_at_first_hook_that_returns_false(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
        ]);

        $workflow = Dynaflow::create([
            'name' => ['en' => 'Post Update Workflow'],
            'topic' => Post::class,
            'action' => 'update',
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        $executionOrder = [];

        // First hook returns true
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) use (&$executionOrder) {
            $executionOrder[] = 'first';

            return true;
        });

        // Second hook returns false - should stop here
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) use (&$executionOrder) {
            $executionOrder[] = 'second';

            return false;
        });

        // Third hook should not execute
        DynaflowFacade::beforeTrigger(Post::class, 'update', function ($workflow, $model, $data, $user) use (&$executionOrder) {
            $executionOrder[] = 'third';

            return true;
        });

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function ($instance, $user) {
            $instance->model->update($instance->dynaflowData->data);
        });

        $engine = app(DynaflowEngine::class);

        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['title' => 'New Title'],
            $user
        );

        $this->assertEquals(['first', 'second'], $executionOrder);
        $this->assertInstanceOf(Post::class, $result); // Workflow was skipped
    }
}
