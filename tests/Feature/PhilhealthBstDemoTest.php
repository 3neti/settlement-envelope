<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotSettleableException;
use LBHurtado\SettlementEnvelope\Exceptions\PayloadValidationException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeChecklistItem;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;
use LBHurtado\SettlementEnvelope\Services\PayloadValidator;

beforeEach(function () {
    config()->set('filesystems.disks.settlement-envelope-drivers.root', __DIR__.'/../Fixtures/drivers');
    config()->set('settlement-envelope.storage_disk', 'demo-private-evidence');
    Storage::fake('demo-private-evidence', ['visibility' => 'private']);

    $this->service = app(EnvelopeService::class);
    $this->payload = [
        'patient_name' => 'Demo Patient',
        'patient_mobile' => '09170000000',
        'reference' => 'DEMO-CLAIM-001',
    ];
});

test('canonical demo declares evidence and host verification without replacing legacy identity', function () {
    $driver = app(DriverService::class)->load('philhealth.bst.demo', '1.0.0');

    expect($driver->getDriverKey())->toBe('philhealth.bst.demo@1.0.0')
        ->and($driver->getGateDefinition('settleable')->rule)
        ->toBe('checklist.required_accepted && signal.amount_verified')
        ->and($driver->getSignalDefinition('amount_verified')->source)->toBe('host')
        ->and($driver->getSignalDefinition('amount_verified')->default)->toBeFalse()
        ->and($driver->getChecklistItem('claim_form')->review)->toBe('none')
        ->and($driver->getChecklistItem('hospital_bill')->review)->toBe('none');
});

test('demo blocks each missing patient or reference field even with evidence and verification', function (string $missingField) {
    unset($this->payload[$missingField]);
    $driverService = app(DriverService::class);
    $driver = $driverService->load('philhealth.bst.demo', '1.0.0');

    expect(fn () => app(PayloadValidator::class)->validate($this->payload, $driver, $driverService->getSchema($driver)))
        ->toThrow(PayloadValidationException::class);

    $envelope = $this->service->create('DEMO-MISSING-FIELD', 'philhealth.bst.demo', '1.0.0', initialPayload: $this->payload);
    foreach (['CLAIM_FORM', 'HOSPITAL_BILL'] as $documentType) {
        $this->service->uploadAttachment($envelope->fresh(), $documentType, UploadedFile::fake()->create('demo.pdf', 10, 'application/pdf'));
    }
    $this->service->setSignal($envelope->fresh(), 'amount_verified', true);

    expect($envelope->fresh()->isSettleable())->toBeFalse();
    expect(fn () => $this->service->lock($envelope->fresh()))->toThrow(EnvelopeNotSettleableException::class);
})->with(['patient_name', 'patient_mobile', 'reference']);

test('demo blocks each missing evidence document despite authoritative amount verification', function (string $missingDocument) {
    $envelope = $this->service->create('DEMO-MISSING-DOC', 'philhealth.bst.demo', '1.0.0', initialPayload: $this->payload);
    foreach (array_diff(['CLAIM_FORM', 'HOSPITAL_BILL'], [$missingDocument]) as $documentType) {
        $this->service->uploadAttachment($envelope->fresh(), $documentType, UploadedFile::fake()->create('demo.pdf', 10, 'application/pdf'));
    }
    $this->service->setSignal($envelope->fresh(), 'amount_verified', true);

    expect($envelope->fresh()->isSettleable())->toBeFalse();
    expect(fn () => $this->service->lock($envelope->fresh()))->toThrow(EnvelopeNotSettleableException::class);
})->with(['CLAIM_FORM', 'HOSPITAL_BILL']);

test('demo becomes ready only after submitted evidence and separate amount verification', function () {
    $this->payload['amount_verified'] = true;
    $envelope = $this->service->create('DEMO-READY', 'philhealth.bst.demo', '1.0.0', initialPayload: $this->payload);

    foreach (['CLAIM_FORM', 'HOSPITAL_BILL'] as $documentType) {
        $file = UploadedFile::fake()->create('demo.pdf', 10, 'application/pdf');
        $expectedHash = hash_file('sha256', $file->getPathname());
        $attachment = $this->service->uploadAttachment($envelope->fresh(), $documentType, $file)->fresh();

        expect($attachment->disk)->toBe('demo-private-evidence')
            ->and($attachment->file_path)->toStartWith("envelopes/{$envelope->id}/{$documentType}/")
            ->and($attachment->hash)->toBe($expectedHash);

        $disk = Storage::disk('demo-private-evidence');
        $disk->assertExists($attachment->file_path);
        expect($disk->getVisibility($attachment->file_path))->toBe('private')
            ->and(hash('sha256', $disk->get($attachment->file_path)))->toBe($attachment->hash);
    }

    expect($envelope->fresh()->checklistItems->every(fn (EnvelopeChecklistItem $item): bool => $item->status->value === 'accepted'))->toBeTrue()
        ->and($envelope->fresh()->signals->firstWhere('key', 'amount_verified')->getBoolValue())->toBeFalse()
        ->and($envelope->fresh()->isSettleable())->toBeFalse();
    expect(fn () => $this->service->lock($envelope->fresh()))->toThrow(EnvelopeNotSettleableException::class);

    $this->service->setSignal($envelope->fresh(), 'amount_verified', true);

    expect($envelope->fresh()->isSettleable())->toBeTrue()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::READY_TO_SETTLE)
        ->and($envelope->fresh()->locked_at)->toBeNull()
        ->and($envelope->fresh()->settled_at)->toBeNull();

    $this->service->setSignal($envelope->fresh(), 'amount_verified', false);
    expect($envelope->fresh()->isSettleable())->toBeFalse();
    expect(fn () => $this->service->lock($envelope->fresh()))->toThrow(EnvelopeNotSettleableException::class);
});

test('demo rejects a sequential duplicate claim reference case insensitively without creating another envelope', function () {
    $envelope = $this->service->create('DEMO-FIRST', 'philhealth.bst.demo', '1.0.0', initialPayload: $this->payload);
    $this->payload['reference'] = strtolower($this->payload['reference']);

    expect(fn () => $this->service->create('DEMO-DUPLICATE', 'philhealth.bst.demo', '1.0.0', initialPayload: $this->payload))
        ->toThrow(PayloadValidationException::class, 'Unique constraint violation')
        ->and(Envelope::query()->count())->toBe(1);

    $updated = $this->service->updatePayload($envelope->fresh(), ['patient_name' => 'Updated Demo Patient']);
    expect($updated->payload['patient_name'])->toBe('Updated Demo Patient');
});
