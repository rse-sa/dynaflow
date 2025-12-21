<?php

namespace RSE\DynaFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowData;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepAssignee;
use RSE\DynaFlow\Notifications\DynaflowStepNotification;
use RSE\DynaFlow\Services\DynaflowEngine;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class StepDurationAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_detects_when_step_should_auto_reject(): void
    {
        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'max_duration_to_reject' => 24, // 24 hours
            ],
        ]);

        $this->assertTrue($step->shouldAutoReject(25)); // 25 hours - should reject
        $this->assertFalse($step->shouldAutoReject(23)); // 23 hours - should not reject
        $this->assertTrue($step->shouldAutoReject(24)); // Exactly 24 hours - should reject
    }

    #[Test]
    public function it_detects_when_step_should_auto_accept(): void
    {
        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'max_duration_to_accept' => 48, // 48 hours
            ],
        ]);

        $this->assertTrue($step->shouldAutoAccept(50)); // 50 hours - should accept
        $this->assertFalse($step->shouldAutoAccept(47)); // 47 hours - should not accept
        $this->assertTrue($step->shouldAutoAccept(48)); // Exactly 48 hours - should accept
    }

    #[Test]
    public function it_sends_notification_when_step_is_approved_and_notify_is_enabled(): void
    {
        Notification::fake();

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'password']);
        $assignee = User::create(['name' => 'Manager', 'email' => 'manager@example.com', 'password' => 'password']);

        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step1 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'notify_on_approve' => true,
            ],
        ]);

        $step2 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Final Approval'],
            'order' => 2,
            'is_final' => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);

        DynaflowStepAssignee::create([
            'dynaflow_step_id' => $step1->id,
            'assignable_type' => User::class,
            'assignable_id' => $assignee->id,
        ]);

        $instance = DynaflowInstance::create([
            'dynaflow_id' => $workflow->id,
            'status' => 'pending',
            'current_step_id' => $step1->id,
            'triggered_by_type' => User::class,
            'triggered_by_id' => $user->id,
        ]);

        DynaflowData::create([
            'dynaflow_instance_id' => $instance->id,
            'data' => ['title' => 'Test Post', 'content' => 'Test Content'],
            'applied' => false,
        ]);

        $engine = app(DynaflowEngine::class);
        $engine->transitionTo($instance, $step2, $assignee, 'approved');

        Notification::assertSentTo($assignee, DynaflowStepNotification::class);
    }

    #[Test]
    public function it_does_not_send_notification_when_notify_is_disabled(): void
    {
        Notification::fake();

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'password']);
        $assignee = User::create(['name' => 'Manager', 'email' => 'manager@example.com', 'password' => 'password']);

        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step1 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'notify_on_approve' => false, // Disabled
            ],
        ]);

        $step2 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Final Approval'],
            'order' => 2,
            'is_final' => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);

        DynaflowStepAssignee::create([
            'dynaflow_step_id' => $step1->id,
            'assignable_type' => User::class,
            'assignable_id' => $assignee->id,
        ]);

        $instance = DynaflowInstance::create([
            'dynaflow_id' => $workflow->id,
            'status' => 'pending',
            'current_step_id' => $step1->id,
            'triggered_by_type' => User::class,
            'triggered_by_id' => $user->id,
        ]);

        DynaflowData::create([
            'dynaflow_instance_id' => $instance->id,
            'data' => ['title' => 'Test Post', 'content' => 'Test Content'],
            'applied' => false,
        ]);

        $engine = app(DynaflowEngine::class);
        $engine->transitionTo($instance, $step2, $assignee, 'approved');

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_replaces_placeholders_in_notification_subject_and_message(): void
    {
        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'notification_subject' => ['en' => 'Step {step_name} has been {decision}'],
                'notification_message' => ['en' => 'Hello {user_name}, the step {step_name} for {topic} {action} has been {decision}.'],
            ],
        ]);

        $placeholders = [
            'step_name' => 'Manager Review',
            'decision' => 'Approved',
            'topic' => 'Post',
            'action' => 'create',
            'user_name' => 'John Doe',
        ];

        $subject = $step->getNotificationSubject($placeholders);
        $message = $step->getNotificationMessage($placeholders);

        $this->assertEquals('Step Manager Review has been Approved', $subject);
        $this->assertEquals('Hello John Doe, the step Manager Review for Post create has been Approved.', $message);
    }

    #[Test]
    public function it_sends_notification_on_reject_when_enabled(): void
    {
        Notification::fake();

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'password']);
        $assignee = User::create(['name' => 'Manager', 'email' => 'manager@example.com', 'password' => 'password']);

        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step1 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'notify_on_reject' => true,
            ],
        ]);

        $step2 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Final Approval'],
            'order' => 2,
            'is_final' => true,
        ]);

        $step1->allowedTransitions()->attach($step2->id);

        DynaflowStepAssignee::create([
            'dynaflow_step_id' => $step1->id,
            'assignable_type' => User::class,
            'assignable_id' => $assignee->id,
        ]);

        $instance = DynaflowInstance::create([
            'dynaflow_id' => $workflow->id,
            'status' => 'pending',
            'current_step_id' => $step1->id,
            'triggered_by_type' => User::class,
            'triggered_by_id' => $user->id,
        ]);

        DynaflowData::create([
            'dynaflow_instance_id' => $instance->id,
            'data' => ['title' => 'Test Post', 'content' => 'Test Content'],
            'applied' => false,
        ]);

        $engine = app(DynaflowEngine::class);
        $engine->transitionTo($instance, $step2, $assignee, 'rejected');

        Notification::assertSentTo($assignee, DynaflowStepNotification::class);
    }

    #[Test]
    public function it_sends_notification_on_edit_request_when_enabled(): void
    {
        Notification::fake();

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'password']);
        $assignee = User::create(['name' => 'Manager', 'email' => 'manager@example.com', 'password' => 'password']);

        $workflow = Dynaflow::create([
            'topic' => Post::class,
            'action' => 'create',
            'name' => ['en' => 'Post Approval'],
            'active' => true,
        ]);

        $step1 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Manager Review'],
            'order' => 1,
            'metadata' => [
                'notify_on_edit_request' => true,
            ],
        ]);

        $step2 = DynaflowStep::create([
            'dynaflow_id' => $workflow->id,
            'name' => ['en' => 'Revision'],
            'order' => 2,
        ]);

        $step1->allowedTransitions()->attach($step2->id);

        DynaflowStepAssignee::create([
            'dynaflow_step_id' => $step1->id,
            'assignable_type' => User::class,
            'assignable_id' => $assignee->id,
        ]);

        $instance = DynaflowInstance::create([
            'dynaflow_id' => $workflow->id,
            'status' => 'pending',
            'current_step_id' => $step1->id,
            'triggered_by_type' => User::class,
            'triggered_by_id' => $user->id,
        ]);

        DynaflowData::create([
            'dynaflow_instance_id' => $instance->id,
            'data' => ['title' => 'Test Post', 'content' => 'Test Content'],
            'applied' => false,
        ]);

        $engine = app(DynaflowEngine::class);
        $engine->transitionTo($instance, $step2, $assignee, 'request_edit');

        Notification::assertSentTo($assignee, DynaflowStepNotification::class);
    }
}
