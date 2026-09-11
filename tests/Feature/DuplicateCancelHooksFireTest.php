<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Facades\Dynaflow as DynaflowFacade;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class DuplicateCancelHooksFireTest extends TestCase
{
    use RefreshDatabase;

    public function test_fires_cancel_hooks_and_writes_an_audit_row_when_a_duplicate_is_superseded(): void
    {
        $engine = app(DynaflowEngine::class);
        $user   = User::factory()->create();
        $post   = Post::create(['title' => 'Test Post', 'view_count' => 0]);

        $workflow = Dynaflow::factory()->create([
            'topic'  => Post::class,
            'action' => 'update',
            'active' => true,
        ]);
        DynaflowStep::factory()->create(['dynaflow_id' => $workflow->id]);

        $seenDecision = null;
        DynaflowFacade::forWorkflow(Post::class, 'update')
            ->whenCancelled()
            ->execute(function ($ctx) use (&$seenDecision) {
                $seenDecision = $ctx->decision;
            });

        $first  = $engine->trigger(Post::class, 'update', $post, ['title' => 'a'], $user);
        $second = $engine->trigger(Post::class, 'update', $post, ['title' => 'b'], $user);

        $first->refresh();

        $this->assertEquals('cancelled', $first->status);
        $this->assertEquals('pending', $second->status);
        $this->assertEquals('cancelled_on_duplicate', $seenDecision);
        $this->assertTrue(
            DynaflowStepExecution::where('dynaflow_instance_id', $first->id)
                ->where('decision', 'cancelled_on_duplicate')
                ->exists()
        );
    }
}
