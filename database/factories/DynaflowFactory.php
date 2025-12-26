<?php

namespace RSE\DynaFlow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RSE\DynaFlow\Enums\BypassMode;
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
            'active'   => true,
            'metadata' => null,
        ];
    }

    /**
     * Set bypass mode with optional custom steps
     */
    public function withBypassMode(string $mode, ?array $steps = null): static
    {
        return $this->state(function (array $attributes) use ($mode, $steps) {
            $metadata           = $attributes['metadata'] ?? [];
            $metadata['bypass'] = ['mode' => $mode];

            if ($mode === BypassMode::CUSTOM_STEPS->value && $steps) {
                $metadata['bypass']['steps'] = $steps;
            }

            return ['metadata' => $metadata];
        });
    }

    /**
     * Set bypass mode to direct_complete
     */
    public function directComplete(): static
    {
        return $this->withBypassMode(BypassMode::DIRECT_COMPLETE->value);
    }

    /**
     * Set bypass mode to auto_follow
     */
    public function autoFollow(): static
    {
        return $this->withBypassMode('auto_follow');
    }

    /**
     * Set bypass mode to custom_steps with specified steps
     */
    public function customSteps(array $steps): static
    {
        return $this->withBypassMode(BypassMode::CUSTOM_STEPS->value, $steps);
    }
}
