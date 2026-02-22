<?php

use RSE\DynaFlow\Models\DynaflowData;
use RSE\DynaFlow\Models\DynaflowInstance;

if (! function_exists('dynaflowInstanceModel')) {
    /**
     * Get the configured DynaflowInstance model class.
     *
     * @return class-string<DynaflowInstance>
     */
    function dynaflowInstanceModel(): string
    {
        return config('dynaflow.models.instance', DynaflowInstance::class);
    }
}

if (! function_exists('dynaflowDataModel')) {
    /**
     * Get the configured DynaflowData model class.
     *
     * @return class-string<DynaflowData>
     */
    function dynaflowDataModel(): string
    {
        return config('dynaflow.models.data', DynaflowData::class);
    }
}
