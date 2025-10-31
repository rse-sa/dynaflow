<?php

namespace RSE\DynaFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RSE\DynaFlow\Models\DynaflowInstance;

class DynaflowTriggered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public DynaflowInstance $instance
    ) {}
}
