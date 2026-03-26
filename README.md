# Settlement Envelope

A driver-based evidence envelope system for settlement gating. This package provides a structured way to collect and validate evidence before allowing settlement of financial transactions.

## Installation

```bash
composer require 3neti/settlement-envelope
```

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
