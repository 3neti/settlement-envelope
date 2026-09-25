# Typed workflow catalog

The catalog is a read-only discovery boundary, not a workflow executor or insurer
approval system. Existing envelope drivers without a `workflow` section continue
working and do not appear as campaign services.

## Host integration

Resolve `Contracts\WorkflowCatalog` from Laravel's container. Supply a
`Data\WorkflowContext` built server-side from the authenticated actor and account;
never trust account IDs submitted by the browser. Bind `WorkflowAccessPolicy` to
the host's policy implementation. The package's default denies every workflow.

`available($context)` returns authorized descriptors. `resolve($id, $version,
$context)` checks access again and requires the exact definition. The catalog does
not fall back to a newer driver or unpinned inherited definition. Do not cache a
catalog result globally across users. Re-resolve on publication and check readiness.

`WorkflowDescriptor` contains typed workflow/plan/notification metadata, document
and checklist requirements, gate names and local configuration readiness. It does
not serialize the host's connection URL, authentication data or raw configuration.

## Optional workflow definition

```yaml
driver:
  id: example.purchase
  version: 1.0.0
  title: Demonstration purchase

# Existing payload/documents/checklist/signals/gates sections still apply.
workflow:
  service: example
  title: Purchase demonstration product
  entry_methods: [payment_qr, public_endpoint]
  connection: example-demo
  requires_review: false
  plans:
    - code: PA5000_DAY
      version: '1'
      title: Personal Accident demonstration
      currency: PHP
      premium_minor: 50000
      benefit_minor: 500000
      coverage:
        basis: day
        duration_days: 1
  notifications:
    - event: payment_received
      sms: 'Payment received for {plan_name}. Continue: {claim_url}'
      placeholders: [plan_name, claim_url]
      allow_override: false
```

This is discovery metadata. Declaring a notification does not send it, declaring a
plan does not create a provider product, and declaring an entry method does not
create a public route or payment QR. Provider/product definitions must be verified
before production use. Host execution must enforce placeholder value safety and
retained mandatory notices. A rendered message is not evidence of delivery.

For a reviewed application, omit plans and connection, choose `public_endpoint`
and set `requires_review: true`. Requirements remain in the canonical document and
checklist sections; workflow metadata does not replace them. Plans in this first
contract share those requirements; no implicit per-plan merge behavior is defined.
Exact-version `extends` can inherit workflow metadata. Child scalar values override
parents; `plans`, `notifications`, and `entry_methods` lists replace as whole lists,
not by merging individual product terms. Day coverage requires a positive integer
duration; trip coverage requires an explicit description and no day duration.
Each discoverable child must explicitly opt in with `workflow: {}` or its own
workflow overrides. Inheritance alone does not opt old drivers into the catalog;
legacy unpinned compositions continue using the legacy loader, not discovery.

## Connections

Host-owned entries belong to `settlement-envelope.connections`. Use only named
references in YAML. Configuration checks perform no network request. A configured
connection does not prove valid credentials, provider availability or authorization
to purchase, insure, or disburse. No credentials belong in exported YAML or DTOs.

```php
// Host configuration; illustrative names, never real credentials in source.
'connections' => [
    'example-demo' => [
        'driver' => 'http',
        'base_url' => env('EXAMPLE_API_URL'),
        'auth' => [
            'type' => 'bearer',
            'token' => env('EXAMPLE_API_TOKEN'),
        ],
        'connect_timeout' => 5,
        'timeout' => 15,
    ],
],
```

Current readiness validation supports HTTP over HTTPS, auth `none` or `bearer`,
and integer timeouts in seconds (positive, at most 120, with total timeout not
shorter than connect timeout). Missing/invalid settings produce safe reason codes.
It is not a general endpoint safety certification or a connectivity probe.

Transport execution and external-system adapters remain separate controlled work.
This catalog does not alter existing x-change Pipedream dispositions or callbacks.

## Integration contracts (Gate3)

`WorkflowIntegrationRegistry` accepts a `WorkflowCatalog` and an explicit iterable
of `WorkflowIntegrationAdapter` instances. There is no automatic provider binding
or adapter discovery. Each exact workflow ID/version pair may occur only once;
invalid or duplicate registrations fail closed. Resolution first uses the
authorized catalog and then requires configured connection readiness and an exact
adapter match. Missing versions never fall back to latest or another adapter.

`resolve($id, $version, $context)` only returns an adapter. It never calls `submit`,
makes HTTP requests, dispatches jobs, or grants execution authority. Catalog
visibility and connection configuration are not evidence approval, payment proof,
reviewer permission, or permission to purchase, insure, or disburse. The host must
enforce those requirements at its execution boundary.

Adapters implement `workflowId(): string`, `workflowVersion(): string`, and
`submit(WorkflowSubmission $submission): WorkflowIntegrationResult`. Submissions
expose the pinned workflow identity, `idempotencyKey(): string`, and
`fingerprint(): string`. These fields do not themselves implement deduplication;
the host owns durable idempotency and canonical fingerprint validation.

Results expose `reference(): string`, `status(): WorkflowIntegrationStatus`, and
`demonstrationOnly(): bool`. Status values are `submitted`, `awaiting_review`,
`completed`, and `rejected`. A completed demonstration is not provider acceptance
or settlement authority. No transport, retry policy, dispatcher, or live provider
implementation is included by these contracts.

## Explicit authority and validation limits

- Host workflow visibility is not reviewer authority. `requires_review` is a
  declaration, not enforcement or a permission grant.
- Caller-provided payload cannot set authoritative reviewer decisions. Record the
  reviewer, decision, approved amount and provenance at the execution boundary.
- Envelope initial creation and checklist presence have known validation limits;
  validate the full payload/schema at campaign publication and claim submission.
- Catalog exact resolution does not retroactively change legacy `DriverService`
  resolution. Existing journey execution needs a deliberate adoption gate.
- Driver and plan versions must be retained along with resolved terms for each
  published journey; changing files in place is not safe version management.
- Private evidence, malware/quarantine handling, approved-amount validation, payment
  execution, and browser claim UI are not implemented by this discovery contract.
- Descriptors expose document/checklist metadata, not the full input schema.
  Schema-to-form-field integration and provider product-code mapping remain for
  the integration/editor gates. Do not infer missing fields from display text.
