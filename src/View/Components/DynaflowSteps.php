<?php

namespace RSE\DynaFlow\View\Components;

use Illuminate\View\Component;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Services\DynaflowStepVisualizer;

class DynaflowSteps extends Component
{
    public array $data;

    public function __construct(
        public DynaflowInstance $instance
    ) {
        $visualizer = app(DynaflowStepVisualizer::class);
        $this->data = $visualizer->generate($instance);
    }

    public function render()
    {
        return view('dynaflow::components.step-diagram');
    }
}
