# Settlement Envelope

A driver-based evidence envelope system for settlement gating. This package provides a structured way to collect and validate evidence before allowing settlement of financial transactions.

## Installation

```bash
composer require 3neti/settlement-envelope:^1.2
```

The current release supports Laravel 12 and 13 on PHP 8.3 and 8.4.

### Package-owned drivers (unreleased)

Integration packages may register bundled resources from their service provider without publishing host copies:

```php
use LBHurtado\SettlementEnvelope\Services\DriverSourceRegistry;

$this->callAfterResolving(DriverSourceRegistry::class, function (DriverSourceRegistry $sources): void {
    $sources->register('vendor/integration', __DIR__.'/../resources/drivers');
});
```

Each source contains `{driver-id}/v{version}.yaml`; IDs and versions must match the YAML `driver` metadata. Relative external JSON schemas resolve within that driver's source directory; traversal, remote URLs, symlink escapes, and resources over 1 MiB are rejected. Schemas are materialized before inheritance so parent schemas retain their own source. Registration and discovery only read resources: they never resolve adapters or execute transactions.

`DriverService::list()`, `workflowReferences()`, `load()`, and `loadExact()` combine registered sources with the configured host disk. `list()` includes a `source` name (`host` for host files). Identical definitions deduplicate; conflicting exact IDs/versions fail closed. Deliberate host replacements require an exact allowlist entry in `settlement-envelope.driver_host_overrides`, for example `['aui.purchase@1.0.0']`. Package-to-package conflicts always fail. Existing flat host files remain supported; legacy files without workflow metadata do not enter the workflow catalog. An explicitly requested missing version never silently selects another version. Package-aware cache keys include source identity, definitions, and schemas, so old host cache entries cannot shadow bundled resources. Registry state belongs to the application container, not a static global.

## Core Concepts

- **Envelope**: A container bound to a settlement reference (e.g., voucher code, loan ID)
- **Driver**: A YAML configuration that defines the schema, checklist, permissions, and gates
- **Payload**: Versioned JSON metadata attached to the envelope
- **Attachments**: Typed document uploads with review workflow
- **Signals**: External boolean flags (e.g., KYC passed, account created)
- **Gates**: Computed readiness states that determine when settlement is allowed

## Usage

### Creating an Envelope

```php
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;

$service = app(EnvelopeService::class);

$envelope = $service->create(
    referenceCode: 'BST-001',
    driverId: 'bank.home-loan-takeout',
    initialPayload: [
        'borrower' => ['full_name' => 'Juan Dela Cruz'],
        'loan' => ['tcp' => 2000000, 'amount' => 1800000],
    ]
);
```

### Updating Payload

```php
$service->updatePayload($envelope, [
    'loan' => ['ltv' => 0.9]
]);
```

### Uploading Attachments

```php
$attachment = $service->uploadAttachment(
    $envelope,
    'BORROWER_ID_FRONT',
    $uploadedFile
);
```

### Setting Signals

```php
$service->setSignal($envelope, 'kyc_passed', true);
$service->setSignal($envelope, 'account_created', true);
```

### Checking Settleable Status

```php
if ($envelope->isSettleable()) {
    $service->lock($envelope);
    $service->settle($envelope);
}
```

## Driver Configuration

Create YAML driver files in `config/envelope-drivers/`:

```yaml
driver:
  id: "my-driver"
  version: "1.0.0"
  title: "My Settlement Driver"

payload:
  schema:
    id: "my-driver.v1"
    format: "json_schema"
    inline:
      type: "object"
      required: ["name"]
      properties:
        name:
          type: "string"

documents:
  registry:
    - type: "ID_DOCUMENT"
      title: "ID Document"
      allowed_mimes: ["application/pdf", "image/jpeg"]
      max_size_mb: 10

checklist:
  template:
    - key: "name_provided"
      kind: "payload_field"
      payload_pointer: "/name"
      required: true
    - key: "id_uploaded"
      kind: "document"
      doc_type: "ID_DOCUMENT"
      required: true
      review: "required"

signals:
  definitions:
    - key: "approved"
      type: "boolean"
      default: false

gates:
  definitions:
    - key: "settleable"
      rule: "checklist.required_accepted && signal.approved"
```

## Model Integration

Add the `HasEnvelopes` trait to any model:

```php
use LBHurtado\SettlementEnvelope\Traits\HasEnvelopes;

class Voucher extends Model
{
    use HasEnvelopes;
}

// Usage
$voucher->createEnvelope('bank.home-loan-takeout');
$voucher->isEnvelopeSettleable();
```

## Events

The package fires the following events:
- `EnvelopeCreated`
- `PayloadUpdated`
- `AttachmentUploaded`
- `AttachmentReviewed`
- `SignalChanged`
- `GateChanged`

## License

MIT
