<?php

namespace RSE\DynaFlow\Enums;

enum DynaflowAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
