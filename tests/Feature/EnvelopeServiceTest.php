<?php

use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;

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
