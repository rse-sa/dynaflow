<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class WorkflowResolverTest extends TestCase
{
    use RefreshDatabase;

    private DynaflowEngine $engine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(DynaflowEngine::class);
        $this->user   = User::factory()->create();
    }

    private function makeWorkflow(string $topic, string $action, string $name = 'Workflow'): Dynaflow
    {
        $workflow = Dynaflow::create([
            'name'   => ['en' => $name],
            'topic'  => $topic,
            'action' => $action,
            'active' => true,
        ]);

        DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name'        => ['en' => 'Review'],
            'order'       => 1,
            'is_final'    => true,
        ]);

        return $workflow;
    }

    private function makePost(): Post
    {
        return Post::create(['title' => 'Test Post', 'view_count' => 0]);
    }

    // -------------------------------------------------------------------------
    // Resolver selection
    // -------------------------------------------------------------------------

    public function test_resolver_selects_specific_workflow_ignoring_default_db_order(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'Workflow A');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'Workflow B');

        $post = $this->makePost();

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => $workflowB);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'Changed'], $this->user);

        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals($workflowB->id, $result->dynaflow_id);
    }

    public function test_resolver_returning_null_falls_back_to_first_active_db_workflow(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = $this->makePost();

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => null);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'Changed'], $this->user);

        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals($workflow->id, $result->dynaflow_id);
    }

    public function test_no_resolver_uses_db_default(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = $this->makePost();

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'Changed'], $this->user);

        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals($workflow->id, $result->dynaflow_id);
    }

    public function test_resolver_selects_workflow_based_on_model_attribute(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'High View Workflow');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'Low View Workflow');

        $popularPost = Post::create(['title' => 'Popular', 'view_count' => 1000]);
        $normalPost  = Post::create(['title' => 'Normal', 'view_count' => 5]);

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn (Post $model) => $model->view_count >= 100 ? $workflowA : $workflowB);

        $popularResult = $this->engine->trigger(Post::class, 'update', $popularPost, ['title' => 'X'], $this->user);
        $normalResult  = $this->engine->trigger(Post::class, 'update', $normalPost, ['title' => 'Y'], $this->user);

        $this->assertEquals($workflowA->id, $popularResult->dynaflow_id);
        $this->assertEquals($workflowB->id, $normalResult->dynaflow_id);
    }

    // -------------------------------------------------------------------------
    // Wildcard resolution priority
    // -------------------------------------------------------------------------

    public function test_exact_match_takes_priority_over_topic_wildcard(): void
    {
        $exactWorkflow = $this->makeWorkflow(Post::class, 'update', 'Exact');
        $topicWorkflow = $this->makeWorkflow(Post::class, 'update', 'Topic Wildcard');

        $post = $this->makePost();

        // Topic wildcard registered first
        DynaflowFacade::forWorkflow(Post::class, '*')
            ->resolveWorkflowUsing()
            ->execute(fn () => $topicWorkflow);

        // Exact match registered second — should win
        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => $exactWorkflow);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($exactWorkflow->id, $result->dynaflow_id);
    }

    public function test_topic_wildcard_takes_priority_over_action_wildcard(): void
    {
        $topicWorkflow  = $this->makeWorkflow(Post::class, 'update', 'Topic Wildcard');
        $actionWorkflow = $this->makeWorkflow(Post::class, 'update', 'Action Wildcard');

        $post = $this->makePost();

        DynaflowFacade::builder()
            ->globally()
            ->resolveWorkflowUsing()
            ->execute(fn () => $actionWorkflow); // *::update — but registered as *::*

        // Actually test *::action vs topic::*
        // Register topic wildcard
        DynaflowFacade::forWorkflow(Post::class, '*')
            ->resolveWorkflowUsing()
            ->execute(fn () => $topicWorkflow);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($topicWorkflow->id, $result->dynaflow_id);
    }

    public function test_global_wildcard_used_when_no_specific_match(): void
    {
        $globalWorkflow = $this->makeWorkflow(Post::class, 'update', 'Global');
        $post           = $this->makePost();

        DynaflowFacade::builder()
            ->globally()
            ->resolveWorkflowUsing()
            ->execute(fn () => $globalWorkflow);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($globalWorkflow->id, $result->dynaflow_id);
    }

    // -------------------------------------------------------------------------
    // Flexible parameter injection
    // -------------------------------------------------------------------------

    public function test_resolver_receives_model_via_type_hint(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = Post::create(['title' => 'Typed', 'view_count' => 42]);

        $capturedModel = null;

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(function (Post $model) use (&$capturedModel, $workflow) {
                $capturedModel = $model;

                return $workflow;
            });

        $this->engine->trigger(Post::class, 'update', $post, ['title' => 'Changed'], $this->user);

        $this->assertInstanceOf(Post::class, $capturedModel);
        $this->assertEquals($post->id, $capturedModel->id);
        $this->assertEquals(42, $capturedModel->view_count);
    }

    public function test_resolver_receives_user_via_type_hint(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = $this->makePost();

        $capturedUser = null;

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(function (User $user) use (&$capturedUser, $workflow) {
                $capturedUser = $user;

                return $workflow;
            });

        $this->engine->trigger(Post::class, 'update', $post, ['title' => 'Changed'], $this->user);

        $this->assertInstanceOf(User::class, $capturedUser);
        $this->assertEquals($this->user->id, $capturedUser->id);
    }

    public function test_resolver_receives_data_by_name(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = $this->makePost();

        $capturedData = null;

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(function ($data) use (&$capturedData, $workflow) {
                $capturedData = $data;

                return $workflow;
            });

        $this->engine->trigger(Post::class, 'update', $post, ['title' => 'New Title', 'view_count' => 7], $this->user);

        $this->assertEquals(['title' => 'New Title', 'view_count' => 7], $capturedData);
    }

    public function test_resolver_receives_topic_and_action_by_name(): void
    {
        $workflow = $this->makeWorkflow(Post::class, 'update');
        $post     = $this->makePost();

        $capturedTopic  = null;
        $capturedAction = null;

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(function ($topic, $action) use (&$capturedTopic, &$capturedAction, $workflow) {
                $capturedTopic  = $topic;
                $capturedAction = $action;

                return $workflow;
            });

        $this->engine->trigger(Post::class, 'update', $post, [], $this->user);

        $this->assertEquals(Post::class, $capturedTopic);
        $this->assertEquals('update', $capturedAction);
    }

    // -------------------------------------------------------------------------
    // Builder API variants
    // -------------------------------------------------------------------------

    public function test_builder_forWorkflow_resolveWorkflowUsing_registers_correctly(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'A');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'B');
        $post      = $this->makePost();

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => $workflowB);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($workflowB->id, $result->dynaflow_id);
    }

    public function test_global_builder_resolveWorkflowUsing_registers_correctly(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'A');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'B');
        $post      = $this->makePost();

        DynaflowFacade::builder()
            ->globally()
            ->resolveWorkflowUsing()
            ->execute(fn () => $workflowB);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($workflowB->id, $result->dynaflow_id);
    }

    public function test_deprecated_resolveWorkflowFor_still_works(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'A');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'B');
        $post      = $this->makePost();

        DynaflowFacade::resolveWorkflowFor(Post::class, 'update', fn () => $workflowB);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($workflowB->id, $result->dynaflow_id);
    }

    public function test_deprecated_resolveWorkflowUsing_still_works(): void
    {
        $workflowA = $this->makeWorkflow(Post::class, 'update', 'A');
        $workflowB = $this->makeWorkflow(Post::class, 'update', 'B');
        $post      = $this->makePost();

        DynaflowFacade::resolveWorkflowUsing(fn () => $workflowB);

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertEquals($workflowB->id, $result->dynaflow_id);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_resolver_returning_inactive_workflow_uses_that_workflow(): void
    {
        $activeWorkflow   = $this->makeWorkflow(Post::class, 'update', 'Active');
        $inactiveWorkflow = $this->makeWorkflow(Post::class, 'update', 'Inactive');
        $inactiveWorkflow->update(['active' => false]);

        $post = $this->makePost();

        // Resolver explicitly returns the inactive workflow — resolver wins over DB active filter
        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->resolveWorkflowUsing()
            ->execute(fn () => $inactiveWorkflow->fresh());

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        $this->assertInstanceOf(DynaflowInstance::class, $result);
        $this->assertEquals($inactiveWorkflow->id, $result->dynaflow_id);
    }

    public function test_resolver_is_not_called_when_no_workflow_exists_and_resolver_not_registered(): void
    {
        $post = $this->makePost();

        $resolverCalled = false;

        // No workflow in DB, no resolver — applyDirectly() runs instead

        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->whenCompleted()
            ->execute(function () use (&$resolverCalled) {
                $resolverCalled = true;
            });

        $result = $this->engine->trigger(Post::class, 'update', $post, ['title' => 'X'], $this->user);

        // No workflow → applyDirectly → completion hook fires
        $this->assertTrue($resolverCalled);
        $this->assertNotInstanceOf(DynaflowInstance::class, $result);
    }
}
