<?php

namespace RSE\DynaFlow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Models\Dynaflow;

class DynaflowFactory extends Factory
{
    protected $model = Dynaflow::class;

    public function definition()
    {
        return [
            'name' => [
                'en' => $this->faker->words(3, true),
                'ar' => 'سير عمل',
            ],
            'topic'       => 'App\Models\\' . $this->faker->unique()->word,
            'action'      => $this->faker->randomElement(['create', 'update', 'delete']),
            'description' => [
                'en' => $this->faker->sentence(),
                'ar' => 'وصف',
            ],
            'active' => true,
        ];
    }
}
