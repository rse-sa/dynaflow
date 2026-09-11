<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class NoResolveFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_fall_back_to_any_active_workflow_when_resolver_returns_null_and_fallback_disabled(): void
    {
        $workflow = Dynaflow::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);
        DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id]);

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => null);

        app(DynaflowHookManager::class)->withoutResolveFallback();

        $post = Post::create(['title' => 'Test', 'view_count' => 0]);
        $user = User::factory()->create();

        $result = app(DynaflowEngine::class)->trigger(Post::class, 'update', $post, ['title' => 'x'], $user);

        $this->assertNotInstanceOf(DynaflowInstance::class, $result);
    }

    public function test_still_falls_back_to_db_default_when_fallback_is_not_disabled(): void
    {
        $workflow = Dynaflow::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);
        DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id]);

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => null);

        $post = Post::create(['title' => 'Test', 'view_count' => 0]);
        $user = User::factory()->create();

        $result = app(DynaflowEngine::class)->trigger(Post::class, 'update', $post, ['title' => 'x'], $user);

        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals($workflow->id, $result->dynaflow_id);
    }
}
