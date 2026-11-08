<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Events\AttachmentUploaded;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAttachment;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;

test('links one private stored document idempotently without copying it', function () {
    Storage::fake('local');
    config()->set('filesystems.disks.local.visibility', 'private');
    Storage::disk('local')->put('uploads/evidence.pdf', '%PDF evidence', 'private');
    $service = app(EnvelopeService::class);
    $envelope = $service->create(referenceCode: 'DOC-LINK', driverId: 'simple.test');
    $document = [
        'document_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'doc_type' => 'TEST_DOC',
        'original_filename' => 'evidence.pdf',
        'disk' => 'local', 'file_path' => 'uploads/evidence.pdf',
        'mime_type' => 'application/pdf', 'size' => 13,
        'hash' => hash('sha256', '%PDF evidence'),
    ];

    $first = $service->attachStoredAttachment($envelope, $document);
    $retry = $service->attachStoredAttachment($envelope, $document);

    expect($retry->id)->toBe($first->id)
        ->and($first->audit_reference)->toBe('DOC-LINK-DOC-001')
        ->and($envelope->attachments()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe(['uploads/evidence.pdf']);
});

test('document identities and audit references are unique within an envelope', function (string $changedField, string $changedValue) {
    $envelope = app(EnvelopeService::class)->create(referenceCode: 'DOC-IDENTITY', driverId: 'simple.test');
    $attachment = [
        'envelope_id' => $envelope->getKey(), 'doc_type' => 'TEST_DOC',
        'original_filename' => 'invoice.pdf', 'file_path' => 'invoice.pdf',
        'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1,
        'hash' => str_repeat('a', 64), 'document_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'audit_reference' => 'CODE-DOC-001',
    ];
    EnvelopeAttachment::create($attachment);
    $attachment[$changedField] = $changedValue;
    expect(fn () => EnvelopeAttachment::create($attachment))
        ->toThrow(QueryException::class);
})->with([
    'duplicate document ID' => ['audit_reference', 'CODE-DOC-002'],
    'duplicate audit reference' => ['document_id', '01ARZ3NDEKTSV4RRFFQ69G5FAW'],
]);

test('upload persists a stable document identity and rejects a duplicate before storing', function () {
    $service = app(EnvelopeService::class);
    $envelope = $service->create(referenceCode: 'DOC-UPLOAD', driverId: 'simple.test');
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');
    $attachment = $service->uploadAttachment($envelope, 'TEST_DOC', $file, documentId: $id, auditReference: 'DOC-UPLOAD-DOC-001');
    expect($attachment->fresh()->document_id)->toBe($id)
        ->and($attachment->audit_reference)->toBe('DOC-UPLOAD-DOC-001');
    $another = UploadedFile::fake()->create('another.pdf', 10, 'application/pdf');
    expect(fn () => $service->uploadAttachment($envelope, 'TEST_DOC', $another, documentId: $id))
        ->toThrow(InvalidArgumentException::class);
    expect($envelope->attachments()->count())->toBe(1);
});

test('removes the stored file when attachment event handling fails', function () {
    Storage::fake('public');
    Event::listen(AttachmentUploaded::class, function (): void {
        throw new RuntimeException('failure');
    });
    $service = app(EnvelopeService::class);
    $envelope = $service->create(referenceCode: 'DOC-FAILURE', driverId: 'simple.test');
    $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');
    expect(fn () => $service->uploadAttachment($envelope, 'TEST_DOC', $file))->toThrow(RuntimeException::class);
    expect(Storage::disk('public')->allFiles('envelopes'))->toBe([])
        ->and($envelope->attachments()->count())->toBe(0);
});

test('can create envelope with simple test driver', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-001',
        driverId: 'simple.test',
        driverVersion: '1.0.0',
        initialPayload: ['name' => 'Test User']
    );

    expect($envelope)->toBeInstanceOf(Envelope::class)
        ->and($envelope->reference_code)->toBe('TEST-001')
        ->and($envelope->driver_id)->toBe('simple.test')
        ->and($envelope->driver_version)->toBe('1.0.0')
        ->and($envelope->status)->toBe(EnvelopeStatus::DRAFT)
        ->and($envelope->payload)->toBe(['name' => 'Test User'])
        ->and($envelope->payload_version)->toBe(1);
});

test('envelope creates checklist items from driver', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-002',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    expect($envelope->checklistItems)->toHaveCount(3);

    $items = $envelope->checklistItems->pluck('key')->toArray();
    expect($items)->toContain('name_provided', 'test_doc', 'approved_signal');
});

test('envelope initializes signals from driver', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-003',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    expect($envelope->signals)->toHaveCount(1);
    expect($envelope->getSignalBool('approved'))->toBeFalse();
});

test('payload field checklist item is completed when payload contains field', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-004',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    $nameItem = $envelope->checklistItems->firstWhere('key', 'name_provided');
    expect($nameItem->status)->toBe(ChecklistItemStatus::ACCEPTED);
});

test('can update envelope payload', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-005',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    $envelope = $service->updatePayload($envelope, ['amount' => 1000]);

    expect($envelope->payload)->toBe(['name' => 'Test User', 'amount' => 1000])
        ->and($envelope->payload_version)->toBe(2);
});

test('can set signal value', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-006',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    $service->setSignal($envelope, 'approved', true);
    $envelope->refresh();

    expect($envelope->getSignalBool('approved'))->toBeTrue();
});

test('gates compute correctly', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-007',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    // Initially not settleable (no doc, no approval)
    expect($envelope->getGate('settleable'))->toBeFalse();
    expect($envelope->getGate('payload_valid'))->toBeTrue();
    expect($envelope->getGate('checklist_complete'))->toBeFalse();
});

test('can activate envelope', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-008',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    expect($envelope->status)->toBe(EnvelopeStatus::DRAFT);

    $envelope = $service->activate($envelope);

    expect($envelope->status)->toBe(EnvelopeStatus::ACTIVE);
});

test('envelope audit log records actions', function () {
    $service = app(EnvelopeService::class);

    $envelope = $service->create(
        referenceCode: 'TEST-009',
        driverId: 'simple.test',
        initialPayload: ['name' => 'Test User']
    );

    expect($envelope->auditLogs)->toHaveCount(1);
    expect($envelope->auditLogs->first()->action)->toBe('envelope_created');

    $service->updatePayload($envelope, ['amount' => 500]);
    $envelope->refresh();

    // Expects: envelope_created, payload_patch, status_change (auto-advance to IN_PROGRESS)
    expect($envelope->auditLogs)->toHaveCount(3);

    $actions = $envelope->auditLogs->pluck('action')->toArray();
    expect($actions)->toContain('envelope_created')
        ->toContain('payload_patch')
        ->toContain('status_change');
});
