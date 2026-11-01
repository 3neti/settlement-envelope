<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Exceptions\DocumentTypeNotAllowedException;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotEditableException;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotSettleableException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeContributionToken;
use LBHurtado\SettlementEnvelope\Services\CreateContributionInvitation;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;
use LBHurtado\SettlementEnvelope\Services\UploadInvitedContribution;

function makeSimpleEnvelope(EnvelopeService $service, string $ref = 'WF-001'): Envelope
{
    return $service->create(
        referenceCode: $ref,
        driverId: 'simple.test',
        driverVersion: '1.0.0',
        initialPayload: ['name' => 'Test User']
    );
}

function fakePdf(string $name = 'test.pdf', int $kb = 100): UploadedFile
{
    // size in KB
    return UploadedFile::fake()->create($name, $kb, 'application/pdf');
}

function advanceToReadyToSettle(EnvelopeService $service, Envelope $envelope): Envelope
{
    // Upload required doc (review required => NEEDS_REVIEW)
    Storage::fake('public');
    $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());

    // Satisfy required signal to make required_present true (doc is present, signal becomes present)
    $service->setSignal($envelope->fresh(), 'approved', true);

    // Accept attachment => required_accepted true => settleable true => READY_TO_SETTLE
    $attachment = $envelope->fresh()->attachments()->latest()->first();
    $service->reviewAttachment($attachment, 'accepted');

    return $envelope->fresh();
}

beforeEach(function () {
    Storage::fake('public');
});

test('uploadAttachment stores file + sets checklist item to needs_review for review-required docs', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-UPLOAD-001');

    $attachment = $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());

    expect($attachment->doc_type)->toBe('TEST_DOC')
        ->and($attachment->review_status)->toBe('pending')
        ->and($attachment->disk)->toBe(config('settlement-envelope.storage_disk', 'public'));

    $envelope->refresh();
    $item = $envelope->checklistItems->firstWhere('doc_type', 'TEST_DOC');

    // For review-required docs, checklist should be NEEDS_REVIEW after upload
    expect($item->status->value)->toBe('needs_review');
});

test('uploadAttachment rejects unknown doc types', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-UPLOAD-002');

    expect(fn () => $service->uploadAttachment($envelope, 'NOPE_DOC', fakePdf()))
        ->toThrow(DocumentTypeNotAllowedException::class);
});

test('uploadAttachment rejects disallowed mime types', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-UPLOAD-003');

    $exe = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');

    expect(fn () => $service->uploadAttachment($envelope, 'TEST_DOC', $exe))
        ->toThrow(DocumentTypeNotAllowedException::class);
});

test('upload rechecks the envelope state inside the transaction', function () {
    $service = app(EnvelopeService::class);
    $stale = makeSimpleEnvelope($service, 'WF-STALE-UPLOAD');
    Envelope::query()->whereKey($stale->id)->update(['status' => EnvelopeStatus::LOCKED->value]);

    expect(fn () => $service->uploadAttachment($stale, 'TEST_DOC', fakePdf()))
        ->toThrow(EnvelopeNotEditableException::class);
    expect($stale->attachments()->count())->toBe(0);
});

test('invited upload enforces scope and assigns contributor identity on the server', function () {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    Storage::fake('local');
    config()->set('filesystems.disks.local.visibility', 'private');
    $envelope = makeSimpleEnvelope(app(EnvelopeService::class), 'WF-INVITED-UPLOAD');
    $token = EnvelopeContributionToken::query()->create([
        'envelope_id' => $envelope->id,
        'expires_at' => now()->addHour(),
        'metadata' => ['scope' => ['actions' => ['upload'], 'document_types' => ['TEST_DOC']]],
    ]);
    $upload = app(UploadInvitedContribution::class);

    config()->set('filesystems.disks.local.visibility', null);
    expect(fn () => $upload->handle($envelope, $token, 'TEST_DOC', fakePdf()))
        ->toThrow(RuntimeException::class);
    config()->set('filesystems.disks.local.visibility', 'private');

    expect(fn () => $upload->handle($envelope, $token, 'WRONG_DOC', fakePdf()))
        ->toThrow(AuthorizationException::class);
    expect($envelope->attachments()->count())->toBe(0);

    $attachment = $upload->handle($envelope, $token, 'TEST_DOC', fakePdf());
    expect($attachment->disk)->toBe('local')
        ->and($attachment->metadata)->toMatchArray([
            'contributor_role' => 'invited', 'contribution_token_id' => $token->id,
        ])
        ->and($token->fresh()->use_count)->toBe(1);
    Storage::disk('local')->assertExists($attachment->file_path);
});

test('an invitation is scoped to driver document types and its password is hashed', function () {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    DB::table('users')->insert(['id' => 1]);
    $actor = new User;
    $actor->id = 1;
    $envelope = makeSimpleEnvelope(app(EnvelopeService::class), 'WF-INVITE-CREATE');
    $inviter = app(CreateContributionInvitation::class);

    expect(fn () => $inviter->handle($envelope, $actor, ['UNKNOWN'], now()->addDay()))
        ->toThrow(InvalidArgumentException::class);
    $token = $inviter->handle($envelope, $actor, ['TEST_DOC'], now()->addDay(), 'long-password');

    expect($token->verifyPassword('long-password'))->toBeTrue()
        ->and($token->password)->not->toBe('long-password')
        ->and($token->metadata['scope']['document_types'])->toBe(['TEST_DOC'])
        ->and(json_encode($envelope->auditLogs()->get()))->not->toContain($token->token);
});

test('review rechecks the envelope state inside the transaction', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-STALE-REVIEW');
    $attachment = $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    $attachment->setRelation('envelope', $envelope);
    Envelope::query()->whereKey($envelope->id)->update(['status' => EnvelopeStatus::LOCKED->value]);

    expect(fn () => $service->reviewAttachment($attachment, 'accepted'))
        ->toThrow(EnvelopeNotEditableException::class);
    expect($attachment->fresh()->review_status)->toBe('pending');
});

test('reviewAttachment can accept and flips checklist item to accepted', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REVIEW-001');

    $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    $attachment = $envelope->fresh()->attachments()->latest()->first();

    $service->reviewAttachment($attachment, 'accepted');

    $envelope->refresh();
    $item = $envelope->checklistItems->firstWhere('doc_type', 'TEST_DOC');

    expect($attachment->fresh()->review_status)->toBe('accepted')
        ->and($item->status->value)->toBe('accepted');
});

test('reviewAttachment can reject and flips checklist item to rejected', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REVIEW-002');

    $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    $attachment = $envelope->fresh()->attachments()->latest()->first();

    $service->reviewAttachment($attachment, 'rejected', null, 'bad scan');

    $envelope->refresh();
    $item = $envelope->checklistItems->firstWhere('doc_type', 'TEST_DOC');

    expect($attachment->fresh()->review_status)->toBe('rejected')
        ->and($item->status->value)->toBe('rejected');
});

test('pending-only review cannot replace a prior review decision', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REVIEW-ONCE');
    $attachment = $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    $service->reviewAttachment($attachment, 'rejected', reason: 'Please resubmit', requirePending: true);

    expect(fn () => $service->reviewAttachment($attachment, 'accepted', requirePending: true))
        ->toThrow(LogicException::class);
    expect($attachment->fresh()->review_status)->toBe('rejected');
});

test('ready_to_settle requires doc accepted + required signals satisfied', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-STATE-001');

    // Upload doc only => not ready
    $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    expect($envelope->fresh()->status)->not->toBe(EnvelopeStatus::READY_TO_SETTLE);

    // Set required signal => should reach READY_FOR_REVIEW (doc present, signal present)
    $service->setSignal($envelope->fresh(), 'approved', true);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::READY_FOR_REVIEW);

    // Accept doc => READY_TO_SETTLE
    $attachment = $envelope->fresh()->attachments()->latest()->first();
    $service->reviewAttachment($attachment, 'accepted');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::READY_TO_SETTLE)
        ->and($envelope->fresh()->isSettleable())->toBeTrue();
});

test('cannot lock unless in READY_TO_SETTLE and settleable gate is true', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-LOCK-001');

    // Not ready yet
    expect(fn () => $service->lock($envelope))
        ->toThrow(EnvelopeNotSettleableException::class);

    // Advance properly
    $envelope = advanceToReadyToSettle($service, $envelope);

    expect($envelope->status)->toBe(EnvelopeStatus::READY_TO_SETTLE)
        ->and($envelope->isSettleable())->toBeTrue();

    $locked = $service->lock($envelope);
    expect($locked->status)->toBe(EnvelopeStatus::LOCKED)
        ->and($locked->locked_at)->not->toBeNull();
});

test('cannot settle unless locked', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-SETTLE-001');

    expect(fn () => $service->settle($envelope))
        ->toThrow(EnvelopeNotSettleableException::class);

    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);

    $settled = $service->settle($envelope);
    expect($settled->status)->toBe(EnvelopeStatus::SETTLED)
        ->and($settled->settled_at)->not->toBeNull();
});

test('LOCKED envelope is not editable: payload updates and uploads should throw', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-LOCK-EDIT-001');

    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);

    expect($envelope->canEdit())->toBeFalse();

    expect(fn () => $service->updatePayload($envelope, ['amount' => 123]))
        ->toThrow(EnvelopeNotEditableException::class);

    expect(fn () => $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf()))
        ->toThrow(EnvelopeNotEditableException::class);
});

test('reopen requires reason and only works when locked', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REOPEN-001');

    // Not locked => cannot reopen
    expect(fn () => $service->reopen($envelope, null, 'oops'))
        ->toThrow(EnvelopeNotEditableException::class);

    // Lock then reopen
    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);

    expect(fn () => $service->reopen($envelope, null, null))
        ->toThrow(InvalidArgumentException::class);

    $reopened = $service->reopen($envelope, null, 'need correction');
    expect($reopened->status)->toBe(EnvelopeStatus::REOPENED)
        ->and($reopened->locked_at)->toBeNull();
});

test('reject requires reason and transitions to REJECTED state', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REJECT-001');

    // Reason required
    expect(fn () => $service->reject($envelope, null, null))
        ->toThrow(InvalidArgumentException::class);

    $rejected = $service->reject($envelope, null, 'fraud detected');
    expect($rejected->status)->toBe(EnvelopeStatus::REJECTED);
});

test('cannot reject already settled envelope', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-REJECT-002');

    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);
    $envelope = $service->settle($envelope);

    expect(fn () => $service->reject($envelope, null, 'too late'))
        ->toThrow(EnvelopeNotEditableException::class);
});

test('cancel requires envelope to not be in terminal state', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-CANCEL-001');

    $cancelled = $service->cancel($envelope, null, 'user requested');
    expect($cancelled->status)->toBe(EnvelopeStatus::CANCELLED)
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

test('cannot cancel already settled envelope', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-CANCEL-002');

    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);
    $envelope = $service->settle($envelope);

    expect(fn () => $service->cancel($envelope, null, 'too late'))
        ->toThrow(EnvelopeNotEditableException::class);
});

/**
 * -------------------------
 * Future enforcement tests
 * -------------------------
 * These are scaffolds for rules you may implement next.
 */
test('uploader cannot accept/reject their own upload when review is required', function () {
    // Your current EnvelopeService does NOT enforce actor separation in reviewAttachment().
    // Keep this scaffold so you can implement policy enforcement later and unskip.
})->skip('Pending: enforce separation-of-duties (uploader ≠ reviewer)');

test('disallow multiple uploads when doc type multiple=false', function () {
    // Your current uploadAttachment() does not enforce multiplicity.
    // Scaffold now, enforce later.
})->skip('Pending: enforce doc_type multiplicity constraints (multiple=false)');

test('locked envelope cannot review attachments', function () {
    $service = app(EnvelopeService::class);
    $envelope = makeSimpleEnvelope($service, 'WF-LOCK-REVIEW-001');

    $service->uploadAttachment($envelope, 'TEST_DOC', fakePdf());
    $attachment = $envelope->fresh()->attachments()->latest()->first();

    $envelope = advanceToReadyToSettle($service, $envelope);
    $envelope = $service->lock($envelope);

    // reviewAttachment checks envelope->canEdit(), so this should throw
    expect(fn () => $service->reviewAttachment($attachment->fresh(), 'accepted'))
        ->toThrow(EnvelopeNotEditableException::class);
});
