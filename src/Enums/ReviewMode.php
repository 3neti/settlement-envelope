<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum ReviewMode: string
{
    case NONE = 'none';
    case OPTIONAL = 'optional';
    case REQUIRED = 'required';

    public function requiresReview(): bool
    {
        return $this === self::REQUIRED;
    }

    public function allowsReview(): bool
    {
        return $this !== self::NONE;
    }
}
