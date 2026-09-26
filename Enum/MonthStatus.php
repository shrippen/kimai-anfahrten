<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum MonthStatus: string
{
    /** Closed by the user (no approval workflow). */
    case CLOSED = 'closed';
    /** Handed in, waiting for the team lead. Locked. */
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    /** Sent back to the user — not locked. */
    case REJECTED = 'rejected';

    public function label(): string
    {
        return 'mileage.approval.status.' . $this->value;
    }

    public function isLocked(): bool
    {
        return $this !== self::REJECTED;
    }
}
