<?php

namespace RSE\DynaFlow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Models\DynaflowStepExecution;
use RSE\DynaFlow\Tests\Models\User;

class DynaflowStepExecutionFactory extends Factory
{
    protected $model = DynaflowStepExecution::class;

    public function definition()
    {
        $user = User::factory()->create();

        return [
            'dynaflow_instance_id' => DynaflowInstance::factory(),
            'dynaflow_step_id'     => DynaflowStep::factory(),
            'executed_by_type'     => $user->getMorphClass(),
            'executed_by_id'       => $user->getKey(),
            'decision'             => $this->faker->randomElement(['approve', 'reject', 'request_edit', 'cancel']),
            'note'                 => $this->faker->sentence(),
            'duration'       => $this->faker->numberBetween(1, 48) * 60,
            'executed_at'          => now(),
        ];
    }
}
