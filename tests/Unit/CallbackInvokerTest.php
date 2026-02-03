<?php

namespace RSE\DynaFlow\Tests\Unit;

use InvalidArgumentException;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\CallbackInvoker;
use RSE\DynaFlow\Support\DynaflowContext;
use RSE\DynaFlow\Tests\Models\Post;
use RSE\DynaFlow\Tests\Models\User;
use RSE\DynaFlow\Tests\TestCase;

class CallbackInvokerTest extends TestCase
{
    protected CallbackInvoker $invoker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoker = new CallbackInvoker();
    }

    public function test_resolves_by_type_hint()
    {
        $user       = new User(['id' => 1, 'name' => 'John']);
        $post       = new Post(['id' => 1, 'title' => 'Test']);

        $callback = function (User $user, Post $post) {
            return $user->name . ':' . $post->title;
        };

        $result = $this->invoker->invoke($callback, [
            'post' => $post,
            'user' => $user,
        ]);

        $this->assertEquals('John:Test', $result);
    }

    public function test_resolves_by_parameter_name()
    {
        $callback = function ($name, $age) {
            return "$name is $age years old";
        };

        $result = $this->invoker->invoke($callback, [
            'name' => 'Alice',
            'age'  => 30,
        ]);

        $this->assertEquals('Alice is 30 years old', $result);
    }

    public function test_resolves_by_positional_fallback()
    {
        $callback = function ($a, $b, $c) {
            return $a + $b + $c;
        };

        // Positional array (no keys)
        $result = $this->invoker->invoke($callback, [10, 20, 30]);

        $this->assertEquals(60, $result);
    }

    public function test_resolves_with_mixed_order()
    {
        $user = User::factory()->make(['id' => 1, 'name' => 'Bob']);
        $data = ['title' => 'New Post'];

        $callback = function (array $data, User $user) {
            return $user->name . ' creates: ' . $data['title'];
        };

        $result = $this->invoker->invoke($callback, [
            'user' => $user,
            'data' => $data,
        ]);

        $this->assertEquals('Bob creates: New Post', $result);
    }

    public function test_uses_default_value_when_not_found()
    {
        $callback = function ($name = 'Guest', $role = 'User') {
            return "$name ($role)";
        };

        $result = $this->invoker->invoke($callback, [
            'name' => 'Alice',
            // 'role' is missing, should use default
        ]);

        $this->assertEquals('Alice (User)', $result);
    }

    public function test_returns_null_for_nullable_parameter()
    {
        $callback = function (?User $user = null) {
            return $user === null ? 'No user' : $user->name;
        };

        // Empty array - no match, should use default null
        $result = $this->invoker->invoke($callback, []);

        $this->assertEquals('No user', $result);
    }

    public function test_throws_exception_for_unresolvable_parameter()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot resolve parameter '\$user'");

        $callback = function (User $user) {
            return $user;
        };

        // No User object in available params, should throw
        $this->invoker->invoke($callback, [
            'other' => 'value',
            'foo' => 'bar',
        ]);
    }

    public function test_type_hint_takes_priority_over_name()
    {
        $user1 = User::factory()->make(['id' => 1, 'name' => 'User1']);
        $user2 = User::factory()->make(['id' => 2, 'name' => 'User2']);

        $callback = function (User $user) {
            return $user->name;
        };

        // Even though key is 'admin', type hint should match first User object
        $result = $this->invoker->invoke($callback, [
            'admin' => $user1,
            'user'  => $user2,
        ]);

        $this->assertEquals('User1', $result);
    }

    public function test_name_takes_priority_over_positional()
    {
        $callback = function ($name) {
            return "Hello $name";
        };

        $result = $this->invoker->invoke($callback, [
            'other' => 'Wrong',
            'name'  => 'Correct',
        ]);

        $this->assertEquals('Hello Correct', $result);
    }

    public function test_resolves_context_object()
    {
        $ctx = new DynaflowContext(
            instance: DynaflowInstance::factory()->make(),
            targetStep: DynaflowStep::factory()->make(),
            decision: 'approved',
            user: User::factory()->make(['name' => 'Approver'])
        );

        $callback = function (DynaflowContext $context) {
            return $context->decision;
        };

        $result = $this->invoker->invoke($callback, [
            'ctx'     => $ctx,
            'context' => $ctx,
        ]);

        $this->assertEquals('approved', $result);
    }

    public function test_resolves_with_ctx_alias()
    {
        $ctx = new DynaflowContext(
            instance: DynaflowInstance::factory()->make(),
            targetStep: DynaflowStep::factory()->make(),
            decision: 'approved',
            user: User::factory()->make()
        );

        $callback = function (DynaflowContext $ctx) {
            return $ctx->decision;
        };

        $result = $this->invoker->invoke($callback, [
            'ctx'     => $ctx,
            'context' => $ctx,
        ]);

        $this->assertEquals('approved', $result);
    }

    public function test_resolves_multiple_object_types()
    {
        $workflow = Dynaflow::factory()->make(['topic' => Post::class]);
        $step     = DynaflowStep::factory()->make(['key' => 'review']);
        $user     = User::factory()->make(['name' => 'John']);

        $callback = function (Dynaflow $workflow, DynaflowStep $step, User $user) {
            return $workflow->topic . ':' . $step->key . ':' . $user->name;
        };

        $result = $this->invoker->invoke($callback, [
            'workflow' => $workflow,
            'step'     => $step,
            'user'     => $user,
        ]);

        $this->assertEquals(Post::class . ':review:John', $result);
    }

    public function test_resolves_builtin_types_by_name_only()
    {
        $callback = function (string $title, int $count, array $tags) {
            return "$title ($count): " . implode(', ', $tags);
        };

        $result = $this->invoker->invoke($callback, [
            'title' => 'Post',
            'count' => 5,
            'tags'  => ['php', 'laravel'],
        ]);

        $this->assertEquals('Post (5): php, laravel', $result);
    }

    public function test_backward_compatibility_with_positional_args()
    {
        $ctx = new DynaflowContext(
            instance: DynaflowInstance::factory()->make(),
            targetStep: DynaflowStep::factory()->make(),
            decision: 'approved',
            user: User::factory()->make()
        );

        // Old-style callback with positional parameter
        $callback = function ($ctx) {
            return $ctx->decision;
        };

        $result = $this->invoker->invoke($callback, [$ctx]);

        $this->assertEquals('approved', $result);
    }

    public function test_resolves_with_subset_of_parameters()
    {
        $user = User::factory()->make(['name' => 'Alice']);
        $data = ['title' => 'Test'];

        // Callback only needs user, ignores other available parameters
        $callback = function (User $user) {
            return $user->name;
        };

        $result = $this->invoker->invoke($callback, [
            'user'     => $user,
            'data'     => $data,
            'workflow' => Dynaflow::factory()->make(),
            'other'    => 'value',
        ]);

        $this->assertEquals('Alice', $result);
    }

    public function test_resolves_union_types()
    {
        $user = User::factory()->make(['name' => 'Bob']);

        $callback = function (User|Post $entity) {
            return $entity->name ?? $entity->title;
        };

        $result = $this->invoker->invoke($callback, [
            'entity' => $user,
        ]);

        $this->assertEquals('Bob', $result);
    }

    public function test_resolves_nullable_union_types()
    {
        $callback = function (User|null $user = null) {
            return $user ? $user->name : 'Guest';
        };

        // Empty array - no match, should use default null
        $result = $this->invoker->invoke($callback, []);

        $this->assertEquals('Guest', $result);
    }
}
