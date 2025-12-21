<?php

namespace RSE\DynaFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public DynaflowContext $context,
    ) {}
}
