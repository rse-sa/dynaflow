<?php

namespace RSE\DynaFlow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

class DynaflowStepFactory extends Factory
{
    protected $model = DynaflowStep::class;

    public function definition()
    {
        return [
            'dynaflow_id' => Dynaflow::factory(),
            'name'        => [
                'en' => $this->faker->words(3, true),
                'ar' => 'خطوة',
            ],
            'description' => [
                'en' => $this->faker->sentence(),
                'ar' => 'وصف',
            ],
            'order'    => $this->faker->unique()->numberBetween(1, 1000),
            'is_final' => false,
        ];
    }

    public function final()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_final' => true,
            ];
        });
    }
}
