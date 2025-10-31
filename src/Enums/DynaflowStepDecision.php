<?php

namespace RSE\DynaFlow\Enums;

enum DynaflowStepDecision: string
{
    case APPROVE      = 'approve';
    case REJECT       = 'reject';
    case REQUEST_EDIT = 'request_edit';
    case CANCEL       = 'cancel';
}
