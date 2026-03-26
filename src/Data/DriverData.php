<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class DriverData extends Data
{
    public function __construct(
        public string $id,
        public string $version,
        public string $title,
        public ?string $description,
        public ?string $domain,
        public ?string $issuer_type,
        public PayloadConfigData $payload,
        /** @var DataCollection<DocumentTypeData> */
        #[DataCollectionOf(DocumentTypeData::class)]
        public DataCollection $documents,
        /** @var DataCollection<ChecklistTemplateItemData> */
        #[DataCollectionOf(ChecklistTemplateItemData::class)]
        public DataCollection $checklist,
        /** @var DataCollection<SignalDefinitionData> */
        #[DataCollectionOf(SignalDefinitionData::class)]
        public DataCollection $signals,
        /** @var DataCollection<GateDefinitionData> */
        #[DataCollectionOf(GateDefinitionData::class)]
        public DataCollection $gates,
        public ?array $permissions = null,
        public ?AuditConfigData $audit = null,
        public ?ManifestConfigData $manifest = null,
        public ?array $ui = null,
        public ?FormFlowMappingData $form_flow_mapping = null,
        public ?array $scanner = null,
    ) {}

    public function getDriverKey(): string
    {
        return "{$this->id}@{$this->version}";
    }

    public function getDocumentType(string $type): ?DocumentTypeData
    {
        return $this->documents->first(fn (DocumentTypeData $doc) => $doc->type === $type);
    }

    public function getChecklistItem(string $key): ?ChecklistTemplateItemData
    {
        return $this->checklist->first(fn (ChecklistTemplateItemData $item) => $item->key === $key);
    }

    public function getSignalDefinition(string $key): ?SignalDefinitionData
    {
        return $this->signals->first(fn (SignalDefinitionData $signal) => $signal->key === $key);
    }

    public function getGateDefinition(string $key): ?GateDefinitionData
    {
        return $this->gates->first(fn (GateDefinitionData $gate) => $gate->key === $key);
    }
}

class PayloadConfigData extends Data
{
    public function __construct(
        public PayloadSchemaData $schema,
        public ?PayloadStorageData $storage = null,
        public ?PayloadConstraintsData $constraints = null,
    ) {}
}

class PayloadSchemaData extends Data
{
    public function __construct(
        public string $id,
        public string $format = 'json_schema',
        public ?string $uri = null,
        public ?array $inline = null,
    ) {}
}

class PayloadStorageData extends Data
{
    public function __construct(
        public string $mode = 'versioned',
        public string $patch_strategy = 'merge',
    ) {}
}

class DocumentTypeData extends Data
{
    public function __construct(
        public string $type,
        public string $title,
        public array $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'],
        public int $max_size_mb = 10,
        public bool $multiple = false,
    ) {}
}

class ChecklistTemplateItemData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $kind, // document, payload_field, attestation, signal
        public ?string $doc_type = null,
        public ?string $payload_pointer = null,
        public ?string $attestation_type = null,
        public ?string $signal_key = null,
        public bool $required = true,
        public string $review = 'none', // none, optional, required
    ) {}
}

class SignalDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $type = 'boolean',
        public string $source = 'host',
        public mixed $default = false,
        /** Whether this signal must be true for settlement */
        public bool $required = false,
        /** 'integration' (system can set) or 'decision' (reviewer only) */
        public string $signal_category = 'decision',
        /** If true, system can auto-set this signal (only for integration signals) */
        public bool $system_settable = false,
    ) {}

    public function isIntegration(): bool
    {
        return $this->signal_category === 'integration';
    }

    public function isDecision(): bool
    {
        return $this->signal_category === 'decision';
    }
}

class GateDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $rule,
    ) {}
}

class AuditConfigData extends Data
{
    public function __construct(
        public bool $enabled = true,
        public array $capture = ['payload_patch', 'attachment_upload', 'attachment_review', 'signal_set', 'gate_change'],
    ) {}
}

class ManifestConfigData extends Data
{
    public function __construct(
        public bool $enabled = true,
        public ManifestIncludesData $includes = new ManifestIncludesData,
    ) {}
}

class ManifestIncludesData extends Data
{
    public function __construct(
        public bool $payload_hash = true,
        public bool $attachments_hashes = true,
        public bool $envelope_fingerprint = true,
    ) {}
}

class PayloadConstraintsData extends Data
{
    public function __construct(
        /** @var array<UniqueFieldConstraintData> */
        #[DataCollectionOf(UniqueFieldConstraintData::class)]
        public DataCollection $unique_fields,
    ) {}
}

class UniqueFieldConstraintData extends Data
{
    public function __construct(
        public string $field,
        public string $scope = 'global', // 'global' or 'user'
        public bool $case_sensitive = true,
        public string $message = 'Field {value} already exists',
    ) {}
}
