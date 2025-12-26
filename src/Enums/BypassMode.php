<?php

namespace RSE\DynaFlow\Enums;

enum BypassMode: string
{
    case MANUAL         = 'manual';
    case DIRECT_COMPLETE = 'direct_complete';
    case AUTO_FOLLOW    = 'auto_follow';
    case CUSTOM_STEPS   = 'custom_steps';
}
