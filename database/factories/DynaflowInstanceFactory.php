<?php

namespace RSE\DynaFlow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Tests\Models\TestModel;
use RSE\DynaFlow\Tests\Models\User;

class DynaflowInstanceFactory extends Factory
{
    protected $model;

    public function __construct(...$arguments)
    {
        $this->model = dynaflowInstanceModel();
        parent::__construct(...$arguments);
    }

    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'dynaflow_id'       => Dynaflow::factory(),
            'model_type'        => TestModel::class,
            'model_id'          => 1,
            'status'            => 'pending',
            'triggered_by_type' => $user->getMorphClass(),
            'triggered_by_id'   => $user->getKey(),
            'current_step_id'   => null,
        ];
    }
}
