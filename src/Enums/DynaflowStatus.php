<?php

namespace RSE\DynaFlow\Enums;

enum DynaflowStatus: string
{
    case PENDING       = 'pending';
    case COMPLETED     = 'completed';
    case APPROVED      = 'approved';
    case REJECTED      = 'rejected';
    case AUTO_APPROVED = 'auto_approved';
    case AUTO_REJECTED = 'auto_rejected';
    case CANCELLED     = 'cancelled';

    /**
     * Get all statuses that represent a successful workflow completion.
     */
    public static function getSuccessStatuses(): array
    {
        return [
            self::COMPLETED->value,
            self::APPROVED->value,
            self::AUTO_APPROVED->value,
        ];
    }

    /**
     * Get all statuses that represent a failed or terminated workflow.
     */
    public static function getFailureStatuses(): array
    {
        return [
            self::REJECTED->value,
            self::AUTO_REJECTED->value,
            self::CANCELLED->value,
        ];
    }

    /**
     * Check if a status value represents a successful completion.
     */
    public static function isSuccessful(string $status): bool
    {
        return in_array($status, self::getSuccessStatuses());
    }

    /**
     * Check if a status value represents a failed or terminated workflow.
     */
    public static function isFailure(string $status): bool
    {
        return in_array($status, self::getFailureStatuses());
    }
}
