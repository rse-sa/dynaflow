<?php

namespace RSE\DynaFlow;

use Illuminate\Support\Facades\Log;

class DynaflowLogger
{
    public function debug(string $event, array $context = []): void
    {
        if (! config('dynaflow.debug', false)) {
            return;
        }

        $channel = config('dynaflow.log_channel');
        $logger  = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->debug("[Dynaflow] {$event}", $context);
    }
}
