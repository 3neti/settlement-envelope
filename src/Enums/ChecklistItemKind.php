<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum ChecklistItemKind: string
{
    case DOCUMENT = 'document';
    case PAYLOAD_FIELD = 'payload_field';
    case ATTESTATION = 'attestation';
    case SIGNAL = 'signal';

    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'Document',
            self::PAYLOAD_FIELD => 'Payload Field',
            self::ATTESTATION => 'Attestation',
            self::SIGNAL => 'Signal',
        };
    }

    public function requiresUpload(): bool
    {
        return $this === self::DOCUMENT;
    }
}
