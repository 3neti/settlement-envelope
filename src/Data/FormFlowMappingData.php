<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

/**
 * Configuration for mapping form flow collected data to envelope payload/attachments.
 *
 * This enables declarative, per-driver mapping configuration instead of hardcoded logic.
 *
 * Payload mapping syntax:
 *   - Simple path: "bio_fields.name"
 *   - Fallback: "bio_fields.full_name | bio_fields.name" (tries first, falls back to second)
 *   - Type cast: "location_capture.latitude:float" (supports :float, :int, :bool, :string)
 *   - Nested: "location_capture.address.formatted"
 *
 * Attachment mapping:
 *   - source: Path to base64 image in collected data
 *   - filename: Output filename
 *   - mime: MIME type for the file
 */
class FormFlowMappingData extends Data
{
    public function __construct(
        /** @var array<string, array<string, string>> Payload section mappings */
        public array $payload = [],
        /** @var array<string, AttachmentMappingData> Attachment mappings keyed by doc_type */
        public array $attachments = [],
    ) {}

    /**
     * Create from raw YAML array.
     */
    public static function fromArray(?array $data): ?self
    {
        if (empty($data)) {
            return null;
        }

        $attachments = [];
        foreach ($data['attachments'] ?? [] as $docType => $config) {
            $attachments[$docType] = AttachmentMappingData::from($config);
        }

        return new self(
            payload: $data['payload'] ?? [],
            attachments: $attachments,
        );
    }

    /**
     * Check if this mapping has any configuration.
     */
    public function isEmpty(): bool
    {
        return empty($this->payload) && empty($this->attachments);
    }

    /**
     * Get payload mapping for a specific section (e.g., 'redeemer', 'wallet').
     */
    public function getPayloadSection(string $section): array
    {
        return $this->payload[$section] ?? [];
    }

    /**
     * Get attachment mapping for a specific doc type.
     */
    public function getAttachmentMapping(string $docType): ?AttachmentMappingData
    {
        return $this->attachments[$docType] ?? null;
    }

    /**
     * Get all payload section names.
     */
    public function getPayloadSections(): array
    {
        return array_keys($this->payload);
    }

    /**
     * Get all attachment doc types.
     */
    public function getAttachmentDocTypes(): array
    {
        return array_keys($this->attachments);
    }
}

/**
 * Configuration for a single attachment mapping.
 */
class AttachmentMappingData extends Data
{
    public function __construct(
        /** Path to base64 image in collected data (e.g., "selfie_capture.selfie") */
        public string $source,
        /** Output filename (e.g., "selfie.jpg") */
        public string $filename,
        /** MIME type (e.g., "image/jpeg") */
        public string $mime,
    ) {}
}
