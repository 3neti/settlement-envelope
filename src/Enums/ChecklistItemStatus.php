<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum ChecklistItemStatus: string
{
    case MISSING = 'missing';
    case UPLOADED = 'uploaded';
    case NEEDS_REVIEW = 'needs_review';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::MISSING => 'Missing',
            self::UPLOADED => 'Uploaded',
            self::NEEDS_REVIEW => 'Needs Review',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
        };
    }

    public function isComplete(): bool
    {
        return $this === self::ACCEPTED;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::MISSING, self::UPLOADED, self::NEEDS_REVIEW]);
    }
}
