<?php

/** @noinspection PhpUnused */

namespace RSE\DynaFlow\Services;

use RSE\DynaFlow\DynaflowHookManager;
use RSE\DynaFlow\Services\Builders\AfterTransitionHookBuilder;
use RSE\DynaFlow\Services\Builders\AfterTriggerHookBuilder;
use RSE\DynaFlow\Services\Builders\AssigneeResolverBuilder;
use RSE\DynaFlow\Services\Builders\AuthorizationResolverBuilder;
use RSE\DynaFlow\Services\Builders\BeforeTransitionHookBuilder;
use RSE\DynaFlow\Services\Builders\BeforeTriggerHookBuilder;
use RSE\DynaFlow\Services\Builders\CancelHookBuilder;
use RSE\DynaFlow\Services\Builders\CompleteHookBuilder;
use RSE\DynaFlow\Services\Builders\ExceptionResolverBuilder;
use RSE\DynaFlow\Services\Builders\StepActivatedHookBuilder;
use RSE\DynaFlow\Services\Builders\TransitionHookBuilder;

/**
 * Fluent interface for registering Dynaflow hooks.
 *
 * Usage examples:
 *
 * Dynaflow::builder()
 *     ->transition()
 *     ->from('draft')
 *     ->to('review')
 *     ->execute(fn($ctx) => ...);
 *
 * Dynaflow::builder()
 *     ->forWorkflow('Post', 'create')
 *     ->whenStepActivated('approval')
 *     ->execute(fn($instance, $step) => ...);
 */

/**
 * Examples:
 *
 * use App\Models\Post;
 * use RSE\DynaFlow\Facades\Dynaflow;
 *
 * // Transition hooks
 * Dynaflow::builder()
 * ->transition()
 * ->from('draft')
 * ->to(['review', 'approval'])
 * ->execute(function ($ctx) {
 * Log::info('Transitioning from draft to review/approval');
 * });
 *
 * // Step activation (global)
 * Dynaflow::builder()
 * ->whenStepActivated('approval')
 * ->execute(function ($instance, $step) {
 * // Notify assignees
 * });
 *
 * // Step activation (scoped to workflow)
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->whenStepActivated(['approval', 'review'])
 * ->execute(function ($instance, $step) {
 * // Workflow-specific logic
 * });
 *
 * // Before/After transitions
 * Dynaflow::builder()
 * ->beforeTransitionTo('final_approval')
 * ->execute(function ($ctx) {
 * // Validate before transition
 * return $ctx->user->can('approve');
 * });
 *
 * Dynaflow::builder()
 * ->afterTransitionTo('published')
 * ->execute(function ($ctx) {
 * // Send notifications
 * });
 *
 * // Completion hooks
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->whenCompleted()
 * ->execute(function ($ctx) {
 * $ctx->model()->publish();
 * });
 *
 * // Cancellation hooks
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'update')
 * ->whenCancelled()
 * ->execute(function ($ctx) {
 * // Rollback changes
 * });
 *
 * // Before/After trigger
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'update')
 * ->beforeTriggering()
 * ->execute(function ($workflow, $model, $data, $user) {
 * // Skip workflow if minor changes
 * if (only_title_changed($data)) {
 * return false; // Skip workflow
 * }
 * return true; // Continue
 * });
 *
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->afterTriggering()
 * ->execute(function ($workflow, $instance, $model, $user) {
 * // Send notification that workflow started
 * });
 *
 * // Authorization
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->authorizeStepUsing()
 * ->execute(function ($step, $user, $instance) {
 * return $user->can('approve-posts');
 * });
 *
 * // Exception handling
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->resolveExceptionUsing()
 * ->execute(function ($workflow, $user) {
 * return $user->isAdmin();
 * });
 *
 * // Assignee resolution
 * Dynaflow::builder()
 * ->forWorkflow(Post::class, 'create')
 * ->resolveAssigneesUsing()
 * ->execute(function ($step, $user, $instance) {
 * return User::role('editor')->get();
 * });
 *
 * // Global hooks
 * Dynaflow::builder()
 * ->globally()
 * ->whenStepActivated('*')
 * ->execute(function ($instance, $step) {
 * // Log all step activations
 * });
 */
class DynaflowHookBuilder
{
    protected DynaflowHookManager $manager;

    protected ?string $topic = null;

    protected ?string $action = null;

    protected bool $isScoped = false;

    public function __construct(DynaflowHookManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Scope hooks to a specific workflow.
     *
     * @param  string  $topic  The topic (e.g., Post::class)
     * @param  string  $action  The action (e.g., 'create', 'update')
     * @return $this
     */
    public function forWorkflow(string $topic, string $action): static
    {
        $this->topic    = $topic;
        $this->action   = $action;
        $this->isScoped = true;

        return $this;
    }

    /**
     * Reset scope to global.
     *
     * @return $this
     */
    public function globally(): static
    {
        $this->topic    = null;
        $this->action   = null;
        $this->isScoped = false;

        return $this;
    }

    /**
     * Register a hook for when a step becomes active.
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or '*' for all steps
     */
    public function whenStepActivated(string|array $stepIdentifier = '*'): StepActivatedHookBuilder
    {
        return new StepActivatedHookBuilder(
            $this->manager,
            $stepIdentifier,
            $this->topic,
            $this->action
        );
    }

    /**
     * Register a hook before transitioning to a step.
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or array of identifiers
     */
    public function beforeTransitionTo(string|array $stepIdentifier): BeforeTransitionHookBuilder
    {
        return new BeforeTransitionHookBuilder(
            $this->manager,
            $stepIdentifier,
            $this->topic,
            $this->action
        );
    }

    /**
     * Register a hook after transitioning to a step.
     *
     * @param  string|array  $stepIdentifier  Step ID, key, or array of identifiers
     */
    public function afterTransitionTo(string|array $stepIdentifier): AfterTransitionHookBuilder
    {
        return new AfterTransitionHookBuilder(
            $this->manager,
            $stepIdentifier,
            $this->topic,
            $this->action
        );
    }

    /**
     * Register a transition hook (from step to step).
     */
    public function whenTransitioning(): TransitionHookBuilder
    {
        return new TransitionHookBuilder(
            $this->manager,
            $this->topic,
            $this->action
        );
    }

    /**
     * Alias for whenTransitioning().
     */
    public function transition(): TransitionHookBuilder
    {
        return $this->whenTransitioning();
    }

    /**
     * Register a hook for when a workflow completes.
     */
    public function whenCompleted(): CompleteHookBuilder
    {
        return new CompleteHookBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register a hook for when a workflow is cancelled.
     */
    public function whenCancelled(): CancelHookBuilder
    {
        return new CancelHookBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register a hook before triggering a workflow.
     */
    public function beforeTriggering(): BeforeTriggerHookBuilder
    {
        return new BeforeTriggerHookBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register a hook before starting a workflow.
     */
    public function beforeStarting(): BeforeTriggerHookBuilder
    {
        return $this->beforeTriggering();
    }

    /**
     * Register a hook after triggering a workflow.
     */
    public function afterTriggering(): AfterTriggerHookBuilder
    {
        return new AfterTriggerHookBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register a hook after starting a workflow.
     */
    public function whenStarted(): AfterTriggerHookBuilder
    {
        return $this->afterTriggering();
    }

    /**
     * Register an authorization resolver.
     */
    public function authorizeStepUsing(): AuthorizationResolverBuilder
    {
        return new AuthorizationResolverBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register an exception resolver.
     */
    public function resolveExceptionUsing(): ExceptionResolverBuilder
    {
        return new ExceptionResolverBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }

    /**
     * Register an assignee resolver.
     */
    public function resolveAssigneesUsing(): AssigneeResolverBuilder
    {
        return new AssigneeResolverBuilder(
            $this->manager,
            $this->topic ?? '*',
            $this->action ?? '*'
        );
    }
}
