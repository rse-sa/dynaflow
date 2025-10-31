<?php

namespace RSE\DynaFlow\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Tests\TestCase;

class DynaflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_dynaflow()
    {
        $dynaflow = Dynaflow::create([
            'name'        => ['en' => 'Test Workflow', 'ar' => 'سير عمل تجريبي'],
            'topic'       => 'App\Models\Post',
            'action'      => 'update',
            'description' => ['en' => 'Test description'],
            'active'      => true,
        ]);

        $this->assertDatabaseHas('dynaflows', [
            'topic'  => 'App\Models\Post',
            'action' => 'update',
            'active' => true,
        ]);

        $this->assertEquals('Test Workflow', $dynaflow->getTranslation('name', 'en'));
        $this->assertEquals('سير عمل تجريبي', $dynaflow->getTranslation('name', 'ar'));
    }

    public function test_dynaflow_has_steps_relationship()
    {
        $dynaflow = Dynaflow::factory()->create();
        $step     = DynaflowStep::factory()->create(['dynaflow_id' => $dynaflow->id]);

        $this->assertCount(1, $dynaflow->steps);
        $this->assertEquals($step->id, $dynaflow->steps->first()->id);
    }

    public function test_dynaflow_can_be_overridden()
    {
        $original = Dynaflow::factory()->create(['active' => true]);

        // Deactivate original before creating override with same topic/action
        $original->update(['active' => false]);

        $override = Dynaflow::factory()->create([
            'topic'  => $original->topic,
            'action' => $original->action,
            'active' => true,
        ]);

        $original->update(['overridden_by' => $override->id]);

        $this->assertEquals($override->id, $original->overriddenBy->id);
    }
}
