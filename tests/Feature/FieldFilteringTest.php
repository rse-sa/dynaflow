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

class FieldFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_triggers_workflow_when_monitored_field_is_changed(): void
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
            'monitored_fields' => ['title', 'content'], // Only monitor important fields
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

        $engine = app(DynaflowEngine::class);

        // Change monitored field (title) - should trigger workflow
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

    public function test_skips_workflow_when_only_non_monitored_fields_changed(): void
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
            'monitored_fields' => ['title', 'content'], // Only monitor important fields
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

        $engine = app(DynaflowEngine::class);

        // Change non-monitored field (view_count) - should skip workflow
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

    public function test_skips_workflow_when_only_ignored_fields_changed(): void
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
            'ignored_fields' => ['view_count'], // Ignore unimportant fields
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

        $engine = app(DynaflowEngine::class);

        // Change only ignored field - should skip workflow
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

    public function test_triggers_workflow_when_non_ignored_field_changed(): void
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
            'ignored_fields' => ['view_count'], // Ignore unimportant fields
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

        $engine = app(DynaflowEngine::class);

        // Change non-ignored field (title) - should trigger workflow
        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['title' => 'New Title', 'view_count' => 10],
            $user
        );

        $this->assertInstanceOf(\RSE\DynaFlow\Models\DynaflowInstance::class, $result);
        $this->assertEquals('pending', $result->status);
    }

    public function test_triggers_workflow_when_no_field_filtering_configured(): void
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
            // No monitored_fields or ignored_fields
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Review'],
            'order' => 1,
            'is_final' => true,
        ]);

        // Register completion hook
        DynaflowFacade::onComplete(Post::class, 'update', function (\RSE\DynaFlow\Support\DynaflowContext $ctx) {
            $ctx->model()->update($ctx->pendingData());
        });

        $engine = app(DynaflowEngine::class);

        // Change any field - should trigger workflow
        $result = $engine->trigger(
            Post::class,
            'update',
            $post,
            ['view_count' => 10],
            $user
        );

        $this->assertInstanceOf(\RSE\DynaFlow\Models\DynaflowInstance::class, $result);
        $this->assertEquals('pending', $result->status);
    }
}
