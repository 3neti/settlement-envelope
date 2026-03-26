<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

/**
 * Result of a form flow sync operation.
 */
class FormFlowSyncResultData extends Data
{
    public function __construct(
        public bool $success,
        public ?string $error = null,
        public bool $payloadUpdated = false,
        public array $payloadKeys = [],
        public int $attachmentsUploaded = 0,
        public array $attachmentErrors = []
    ) {}

    /**
     * Create a success result.
     */
    public static function success(
        bool $payloadUpdated = false,
        array $payloadKeys = [],
        int $attachmentsUploaded = 0,
        array $attachmentErrors = []
    ): self {
        return new self(
            success: true,
            payloadUpdated: $payloadUpdated,
            payloadKeys: $payloadKeys,
            attachmentsUploaded: $attachmentsUploaded,
            attachmentErrors: $attachmentErrors
        );
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error
        );
    }

    /**
     * Check if the sync had any errors (either failed or attachment errors).
     */
    public function hasErrors(): bool
    {
        return ! $this->success || ! empty($this->attachmentErrors);
    }
}
