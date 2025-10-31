<?php

namespace RSE\DynaFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RSE\DynaFlow\Models\DynaflowStepExecution;

class DynaflowStepExecuted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public DynaflowStepExecution $execution
    ) {}
}
