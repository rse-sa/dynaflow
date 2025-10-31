<?php

namespace RSE\DynaFlow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Tests\Models\TestModel;

class TestModelFactory extends Factory
{
    protected $model = TestModel::class;

    public function definition(): array
    {
        return [
            'title'   => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
        ];
    }
}
