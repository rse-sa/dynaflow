<?php

namespace RSE\DynaFlow\Enums;

enum DynaflowStatus: string
{
    case PENDING   = 'pending';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
