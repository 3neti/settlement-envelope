<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum EnvelopeStatus: string
{
    // Initial state
    case DRAFT = 'draft';

    // Evidence collection states
    case IN_PROGRESS = 'in_progress';
    case READY_FOR_REVIEW = 'ready_for_review';
    case READY_TO_SETTLE = 'ready_to_settle';

    // Terminal/locked states
    case LOCKED = 'locked';
    case SETTLED = 'settled';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';

    // Recovery state
    case REOPENED = 'reopened';

    // Legacy (deprecated, maps to IN_PROGRESS)
    case ACTIVE = 'active';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::IN_PROGRESS, self::ACTIVE => 'In Progress',
            self::READY_FOR_REVIEW => 'Ready for Review',
            self::READY_TO_SETTLE => 'Ready to Settle',
            self::LOCKED => 'Locked',
            self::SETTLED => 'Settled',
            self::CANCELLED => 'Cancelled',
            self::REJECTED => 'Rejected',
            self::REOPENED => 'Reopened',
        };
    }

    /**
     * Can payload/attachments/signals be modified?
     */
    public function canEdit(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::ACTIVE,           // Legacy
            self::IN_PROGRESS,
            self::READY_FOR_REVIEW,
            self::REOPENED,
        ]);
    }

    /**
     * Can the envelope be settled (disbursement triggered)?
     */
    public function canSettle(): bool
    {
        return $this === self::LOCKED;
    }

    /**
     * Can the envelope be locked for settlement?
     */
    public function canLock(): bool
    {
        return $this === self::READY_TO_SETTLE;
    }

    /**
     * Is this a terminal state (no further transitions except admin override)?
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::SETTLED,
            self::CANCELLED,
            self::REJECTED,
        ]);
    }

    /**
     * Can this envelope be rejected?
     */
    public function canReject(): bool
    {
        return ! $this->isTerminal() && $this !== self::LOCKED;
    }

    /**
     * Can this envelope be reopened?
     */
    public function canReopen(): bool
    {
        return $this === self::LOCKED;
    }

    /**
     * Can this envelope be cancelled?
     */
    public function canCancel(): bool
    {
        return ! $this->isTerminal();
    }
}
