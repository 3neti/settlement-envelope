<?php

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LBHurtado\SettlementEnvelope\Data\DriverData;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemKind;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Events\AttachmentReviewed;
use LBHurtado\SettlementEnvelope\Events\AttachmentUploaded;
use LBHurtado\SettlementEnvelope\Events\PayloadUpdated;
use LBHurtado\SettlementEnvelope\Events\SignalChanged;
use LBHurtado\SettlementEnvelope\Exceptions\DocumentTypeNotAllowedException;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotEditableException;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotSettleableException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAttachment;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAuditLog;
use LBHurtado\SettlementEnvelope\Models\EnvelopeChecklistItem;
use LBHurtado\SettlementEnvelope\Models\EnvelopePayloadVersion;
use LBHurtado\SettlementEnvelope\Models\EnvelopeSignal;

class EnvelopeService
{
    public function __construct(
        protected DriverService $driverService,
        protected PayloadValidator $payloadValidator,
        protected GateEvaluator $gateEvaluator
    ) {}

    /**
     * Create a new envelope
     */
    public function create(
        string $referenceCode,
        string $driverId,
        ?string $driverVersion = null,
        ?Model $reference = null,
        ?array $initialPayload = null,
        ?array $context = null,
        ?Model $actor = null
    ): Envelope {
        $driver = $this->driverService->load($driverId, $driverVersion);

        // Validate uniqueness constraints if initial payload provided
        if ($initialPayload) {
            $this->payloadValidator->validateUniqueConstraints($initialPayload, $driver, null);
        }

        return DB::transaction(function () use ($referenceCode, $driver, $reference, $initialPayload, $context, $actor) {
            // Create envelope with version 0 initially
            $envelope = Envelope::create([
                'reference_code' => $referenceCode,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->getKey(),
                'driver_id' => $driver->id,
                'driver_version' => $driver->version,
                'payload' => $initialPayload,
                'payload_version' => 0,
                'status' => EnvelopeStatus::DRAFT,
                'context' => $context,
            ]);

            // Create initial payload version if payload provided
            if ($initialPayload) {
                EnvelopePayloadVersion::createVersion($envelope, $initialPayload, null, $actor);
                $envelope->update(['payload_version' => 1]);
            }

            // Create checklist items from driver template
            $this->createChecklistItems($envelope, $driver);

            // Update payload field checklist items if initial payload provided
            if ($initialPayload) {
                $this->updatePayloadFieldItems($envelope);
            }

            // Initialize signals from driver definitions
            $this->initializeSignals($envelope, $driver);

            // Compute initial gates (skip auto-advance on creation)
            $this->recomputeGates($envelope, skipAutoAdvance: true);

            // Audit log
            $this->audit($envelope, EnvelopeAuditLog::ACTION_CREATED, $actor, null, null, [
                'driver' => $driver->getDriverKey(),
                'reference_code' => $referenceCode,
            ]);

            return $envelope->fresh(['checklistItems', 'signals']);
        });
    }

    /**
     * Update envelope payload
     */
    public function updatePayload(Envelope $envelope, array $patch, ?Model $actor = null): Envelope
    {
        if (! $envelope->canEdit()) {
            throw new EnvelopeNotEditableException("Envelope {$envelope->reference_code} is not editable");
        }

        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);
        $schema = $this->driverService->getSchema($driver);

        $oldPayload = $envelope->payload ?? [];
        $newPayload = $this->payloadValidator->mergePatch($oldPayload, $patch);

        // Validate if schema exists
        if ($schema) {
            $this->payloadValidator->validate($newPayload, $driver, $schema);
        }

        // Validate uniqueness constraints
        $this->payloadValidator->validateUniqueConstraints($newPayload, $driver, $envelope->id);

        return DB::transaction(function () use ($envelope, $newPayload, $patch, $actor, $oldPayload) {
            // Create version record
            EnvelopePayloadVersion::createVersion($envelope, $newPayload, $patch, $actor);

            // Update envelope
            $envelope->update([
                'payload' => $newPayload,
                'payload_version' => $envelope->payload_version + 1,
            ]);

            // Update payload field checklist items
            $this->updatePayloadFieldItems($envelope);

            // Recompute gates
            $this->recomputeGates($envelope);

            // Audit
            $this->audit($envelope, EnvelopeAuditLog::ACTION_PAYLOAD_PATCH, $actor, null, $oldPayload, $newPayload);

            // Event
            event(new PayloadUpdated($envelope, $patch, $actor));

            return $envelope->fresh();
        });
    }

    /**
     * Upload an attachment
     */
    public function uploadAttachment(
        Envelope $envelope,
        string $docType,
        UploadedFile $file,
        ?Model $actor = null,
        ?array $metadata = null
    ): EnvelopeAttachment {
        if (! $envelope->canEdit()) {
            throw new EnvelopeNotEditableException("Envelope {$envelope->reference_code} is not editable");
        }

        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);
        $docTypeConfig = $driver->getDocumentType($docType);

        if (! $docTypeConfig) {
            throw new DocumentTypeNotAllowedException("Document type {$docType} is not allowed for this envelope");
        }

        // Validate file
        $this->validateFile($file, $docTypeConfig);

        // Find checklist item
        $checklistItem = $envelope->checklistItems()
            ->where('doc_type', $docType)
            ->first();

        return DB::transaction(function () use ($envelope, $docType, $file, $actor, $metadata, $checklistItem) {
            // Store file
            $disk = config('settlement-envelope.storage_disk', 'public');
            $path = $file->store("envelopes/{$envelope->id}/{$docType}", $disk);
            $hash = hash_file('sha256', $file->getPathname());

            // Determine initial review status based on checklist item's review_mode
            // If review_mode is 'none', auto-accept the attachment
            $reviewStatus = 'pending';
            if ($checklistItem && $checklistItem->review_mode?->value === 'none') {
                $reviewStatus = 'accepted';
            }

            // Create attachment
            $attachment = EnvelopeAttachment::create([
                'envelope_id' => $envelope->id,
                'checklist_item_id' => $checklistItem?->id,
                'doc_type' => $docType,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'disk' => $disk,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'metadata' => $metadata,
                'uploaded_by' => $actor?->getKey(),
                'review_status' => $reviewStatus,
                'reviewed_at' => $reviewStatus === 'accepted' ? now() : null,
                'reviewed_by' => $reviewStatus === 'accepted' ? $actor?->getKey() : null,
            ]);

            // Update checklist item status
            $checklistItem?->computeStatus();

            // Recompute gates
            $this->recomputeGates($envelope);

            // Audit
            $this->audit($envelope, EnvelopeAuditLog::ACTION_ATTACHMENT_UPLOAD, $actor, null, null, [
                'doc_type' => $docType,
                'filename' => $file->getClientOriginalName(),
                'hash' => $hash,
            ]);

            // Event
            event(new AttachmentUploaded($envelope, $attachment, $actor));

            return $attachment;
        });
    }

    /**
     * Review an attachment (accept/reject)
     */
    public function reviewAttachment(
        EnvelopeAttachment $attachment,
        string $decision,
        ?Model $actor = null,
        ?string $reason = null
    ): void {
        $envelope = $attachment->envelope;

        if (! $envelope->canEdit()) {
            throw new EnvelopeNotEditableException("Envelope {$envelope->reference_code} is not editable");
        }

        $oldStatus = $attachment->review_status;

        DB::transaction(function () use ($attachment, $decision, $actor, $reason, $envelope, $oldStatus) {
            if ($decision === 'accepted') {
                $attachment->accept($actor?->getKey());
            } else {
                $attachment->reject($actor?->getKey(), $reason);
            }

            // Recompute gates
            $this->recomputeGates($envelope);

            // Audit
            $this->audit($envelope, EnvelopeAuditLog::ACTION_ATTACHMENT_REVIEW, $actor, null, [
                'status' => $oldStatus,
            ], [
                'status' => $decision,
                'reason' => $reason,
            ]);

            // Event
            event(new AttachmentReviewed($attachment, $decision, $actor));
        });
    }

    /**
     * Set a signal value
     */
    public function setSignal(
        Envelope $envelope,
        string $key,
        mixed $value,
        ?Model $actor = null
    ): EnvelopeSignal {
        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);
        $signalDef = $driver->getSignalDefinition($key);

        $oldValue = $envelope->getSignal($key);

        $signal = DB::transaction(function () use ($envelope, $key, $value, $actor, $signalDef) {
            $signal = EnvelopeSignal::setSignal(
                $envelope,
                $key,
                $value,
                $signalDef?->type ?? 'boolean',
                $signalDef?->source ?? 'host',
                $actor
            );

            // Update signal checklist items
            $this->updateSignalItems($envelope);

            // Recompute gates
            $this->recomputeGates($envelope);

            return $signal;
        });

        // Audit
        $this->audit($envelope, EnvelopeAuditLog::ACTION_SIGNAL_SET, $actor, null, [
            'key' => $key,
            'value' => $oldValue,
        ], [
            'key' => $key,
            'value' => $value,
        ]);

        // Event
        event(new SignalChanged($envelope, $key, $oldValue, $value, $actor));

        return $signal;
    }

    /**
     * Activate envelope (move from draft to active)
     */
    public function activate(Envelope $envelope, ?Model $actor = null): Envelope
    {
        if ($envelope->status !== EnvelopeStatus::DRAFT) {
            throw new EnvelopeNotEditableException('Only draft envelopes can be activated');
        }

        $envelope->update(['status' => EnvelopeStatus::ACTIVE]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_STATUS_CHANGE, $actor, null, [
            'status' => EnvelopeStatus::DRAFT->value,
        ], [
            'status' => EnvelopeStatus::ACTIVE->value,
        ]);

        return $envelope->fresh();
    }

    /**
     * Lock envelope for settlement
     * Requires envelope to be in READY_TO_SETTLE state (two-phase settlement)
     */
    public function lock(Envelope $envelope, ?Model $actor = null): Envelope
    {
        // Must be in READY_TO_SETTLE state to lock
        if (! $envelope->status->canLock()) {
            throw new EnvelopeNotSettleableException(
                "Envelope {$envelope->reference_code} must be in READY_TO_SETTLE state to lock (current: {$envelope->status->value})"
            );
        }

        // Double-check settleable gate
        if (! $envelope->isSettleable()) {
            throw new EnvelopeNotSettleableException("Envelope {$envelope->reference_code} is not settleable");
        }

        $oldStatus = $envelope->status;

        $envelope->update([
            'status' => EnvelopeStatus::LOCKED,
            'locked_at' => now(),
        ]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_LOCKED, $actor, null, [
            'status' => $oldStatus->value,
        ], [
            'status' => EnvelopeStatus::LOCKED->value,
        ]);

        return $envelope->fresh();
    }

    /**
     * Mark envelope as settled
     */
    public function settle(Envelope $envelope, ?Model $actor = null): Envelope
    {
        if (! $envelope->canSettle()) {
            throw new EnvelopeNotSettleableException('Envelope must be locked before settling');
        }

        $envelope->update([
            'status' => EnvelopeStatus::SETTLED,
            'settled_at' => now(),
        ]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_SETTLED, $actor);

        return $envelope->fresh();
    }

    /**
     * Cancel envelope
     */
    public function cancel(Envelope $envelope, ?Model $actor = null, ?string $reason = null): Envelope
    {
        if (! $envelope->status->canCancel()) {
            throw new EnvelopeNotEditableException("Envelope {$envelope->reference_code} cannot be cancelled in current state");
        }

        $oldStatus = $envelope->status;

        $envelope->update([
            'status' => EnvelopeStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_CANCELLED, $actor, null, [
            'status' => $oldStatus->value,
        ], [
            'status' => EnvelopeStatus::CANCELLED->value,
            'reason' => $reason,
        ]);

        return $envelope->fresh();
    }

    /**
     * Reject envelope (hard stop - requires new envelope or admin reopen)
     */
    public function reject(Envelope $envelope, ?Model $actor = null, ?string $reason = null): Envelope
    {
        if (! $envelope->status->canReject()) {
            throw new EnvelopeNotEditableException("Envelope {$envelope->reference_code} cannot be rejected in current state");
        }

        if (empty($reason)) {
            throw new \InvalidArgumentException('Reason is required when rejecting an envelope');
        }

        $oldStatus = $envelope->status;

        $envelope->update([
            'status' => EnvelopeStatus::REJECTED,
        ]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_REJECTED, $actor, null, [
            'status' => $oldStatus->value,
        ], [
            'status' => EnvelopeStatus::REJECTED->value,
            'reason' => $reason,
        ]);

        return $envelope->fresh();
    }

    /**
     * Reopen a locked envelope (admin only, requires reason)
     */
    public function reopen(Envelope $envelope, ?Model $actor = null, ?string $reason = null): Envelope
    {
        if (! $envelope->status->canReopen()) {
            throw new EnvelopeNotEditableException('Only locked envelopes can be reopened');
        }

        if (empty($reason)) {
            throw new \InvalidArgumentException('Reason is required when reopening an envelope');
        }

        $oldStatus = $envelope->status;

        $envelope->update([
            'status' => EnvelopeStatus::REOPENED,
            'locked_at' => null, // Clear lock timestamp
        ]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_REOPENED, $actor, null, [
            'status' => $oldStatus->value,
        ], [
            'status' => EnvelopeStatus::REOPENED->value,
            'reason' => $reason,
        ]);

        return $envelope->fresh();
    }

    /**
     * Update envelope context/metadata
     */
    public function updateContext(Envelope $envelope, array $context, ?Model $actor = null): Envelope
    {
        $oldContext = $envelope->context ?? [];
        $newContext = array_merge($oldContext, $context);

        $envelope->update(['context' => $newContext]);

        $this->audit($envelope, EnvelopeAuditLog::ACTION_CONTEXT_UPDATE, $actor, null, $oldContext, $newContext);

        return $envelope->fresh();
    }

    /**
     * Compute gates for an envelope
     */
    public function computeGates(Envelope $envelope): array
    {
        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);

        return $this->gateEvaluator->evaluate($envelope, $driver);
    }

    /**
     * Refresh checklist items and recompute gates
     *
     * Used when payload is updated externally (e.g., by contribution links)
     * without going through full updatePayload() validation.
     */
    public function refreshChecklistAndGates(Envelope $envelope): Envelope
    {
        $this->updatePayloadFieldItems($envelope);
        $this->recomputeGates($envelope);

        return $envelope->fresh();
    }

    // Protected helpers

    protected function createChecklistItems(Envelope $envelope, DriverData $driver): void
    {
        foreach ($driver->checklist as $item) {
            EnvelopeChecklistItem::create([
                'envelope_id' => $envelope->id,
                'key' => $item->key,
                'label' => $item->label,
                'kind' => $item->kind,
                'doc_type' => $item->doc_type,
                'payload_pointer' => $item->payload_pointer,
                'attestation_type' => $item->attestation_type,
                'signal_key' => $item->signal_key,
                'required' => $item->required,
                'review_mode' => $item->review,
                'status' => ChecklistItemStatus::MISSING,
            ]);
        }
    }

    protected function initializeSignals(Envelope $envelope, DriverData $driver): void
    {
        foreach ($driver->signals as $signalDef) {
            EnvelopeSignal::create([
                'envelope_id' => $envelope->id,
                'key' => $signalDef->key,
                'type' => $signalDef->type,
                'value' => is_bool($signalDef->default) ? ($signalDef->default ? 'true' : 'false') : (string) $signalDef->default,
                'source' => $signalDef->source,
            ]);
        }
    }

    protected function updatePayloadFieldItems(Envelope $envelope): void
    {
        $envelope->checklistItems()
            ->where('kind', ChecklistItemKind::PAYLOAD_FIELD)
            ->each(function (EnvelopeChecklistItem $item) use ($envelope) {
                $exists = $this->payloadValidator->fieldExists(
                    $envelope->payload ?? [],
                    $item->payload_pointer
                );

                $item->update([
                    'status' => $exists ? ChecklistItemStatus::ACCEPTED : ChecklistItemStatus::MISSING,
                ]);
            });
    }

    protected function updateSignalItems(Envelope $envelope): void
    {
        $envelope->checklistItems()
            ->where('kind', ChecklistItemKind::SIGNAL)
            ->each(function (EnvelopeChecklistItem $item) use ($envelope) {
                $signalValue = $envelope->getSignalBool($item->signal_key);
                $item->update([
                    'status' => $signalValue ? ChecklistItemStatus::ACCEPTED : ChecklistItemStatus::MISSING,
                ]);
            });
    }

    protected function recomputeGates(Envelope $envelope, bool $skipAutoAdvance = false): void
    {
        // Force refresh relationships to ensure latest data is used
        $envelope->load(['checklistItems', 'signals']);

        $gates = $this->computeGates($envelope);
        $envelope->updateGatesCache($gates);

        // Auto-advance state based on computed flags (unless skipped)
        if (! $skipAutoAdvance) {
            $this->autoAdvanceState($envelope, $gates);
        }
    }

    /**
     * Automatically advance envelope state based on computed flags
     * State transitions are idempotent and derived from flags
     * Loops until no more transitions are possible (stable state)
     */
    protected function autoAdvanceState(Envelope $envelope, array $gates): void
    {
        $maxIterations = 10; // Safety limit to prevent infinite loops
        $iterations = 0;
        $initialStatus = $envelope->status; // Track starting state for summary
        $transitionPath = [$initialStatus->value]; // Track full path

        while ($iterations < $maxIterations) {
            $currentStatus = $envelope->status;
            $newStatus = $this->computeNextState($envelope, $gates);

            if (! $newStatus || $newStatus === $currentStatus) {
                // No more transitions - stable state reached
                break;
            }

            $envelope->update(['status' => $newStatus]);
            $envelope->refresh(); // Refresh to get updated status for next iteration
            $transitionPath[] = $newStatus->value;

            $this->audit($envelope, EnvelopeAuditLog::ACTION_STATUS_CHANGE, null, 'system', [
                'status' => $currentStatus->value,
            ], [
                'status' => $newStatus->value,
                'reason' => 'auto_transition',
            ]);

            $iterations++;
        }

        // Add summary audit entry if multiple transitions occurred
        if ($iterations > 1) {
            $finalStatus = $envelope->status;
            $this->audit($envelope, 'auto_advance_summary', null, 'system', [
                'initial_status' => $initialStatus->value,
            ], [
                'final_status' => $finalStatus->value,
                'transition_path' => implode(' → ', $transitionPath),
                'transitions_count' => $iterations,
            ]);
        }
    }

    /**
     * Compute the next state based on current state and flags
     * Returns null if no transition should occur
     */
    protected function computeNextState(Envelope $envelope, array $gates): ?EnvelopeStatus
    {
        $current = $envelope->status;

        // Compute flags directly from envelope data (not from gates which are driver-specific)
        // Force refresh to get latest checklist/signal states
        $envelope->load(['checklistItems', 'signals']);

        $requiredItems = $envelope->checklistItems->where('required', true);
        $requiredCount = $requiredItems->count();

        // required_present: all required items have status != missing
        $requiredPresentCount = $requiredItems
            ->filter(fn ($item) => $item->status->value !== 'missing')
            ->count();
        $requiredPresent = $requiredCount === 0 || $requiredPresentCount === $requiredCount;

        // required_accepted: all required items have status = accepted
        $requiredAcceptedCount = $requiredItems
            ->filter(fn ($item) => $item->status->value === 'accepted')
            ->count();
        $requiredAccepted = $requiredCount === 0 || $requiredAcceptedCount === $requiredCount;

        // settleable gate from driver evaluation
        $settleable = $gates['settleable'] ?? false;

        // DRAFT → IN_PROGRESS: First mutation occurs
        if ($current === EnvelopeStatus::DRAFT) {
            $hasPayload = ! empty($envelope->payload);
            $hasAttachments = $envelope->attachments()->exists();

            if ($hasPayload || $hasAttachments) {
                return EnvelopeStatus::IN_PROGRESS;
            }
        }

        // IN_PROGRESS → READY_FOR_REVIEW: All required items present
        if (in_array($current, [EnvelopeStatus::IN_PROGRESS, EnvelopeStatus::ACTIVE])) {
            if ($requiredPresent) {
                return EnvelopeStatus::READY_FOR_REVIEW;
            }
        }

        // READY_FOR_REVIEW → IN_PROGRESS: Required item becomes missing
        if ($current === EnvelopeStatus::READY_FOR_REVIEW) {
            if (! $requiredPresent) {
                return EnvelopeStatus::IN_PROGRESS;
            }

            // READY_FOR_REVIEW → READY_TO_SETTLE: All gates pass
            if ($requiredAccepted && $settleable) {
                return EnvelopeStatus::READY_TO_SETTLE;
            }
        }

        // REOPENED → IN_PROGRESS: When corrections begin
        if ($current === EnvelopeStatus::REOPENED) {
            return EnvelopeStatus::IN_PROGRESS;
        }

        // Note: READY_TO_SETTLE → LOCKED is NOT automatic
        // It requires explicit lock() call (two-phase settlement)

        return null;
    }

    protected function validateFile(UploadedFile $file, $docTypeConfig): void
    {
        // Check mime type
        if (! in_array($file->getMimeType(), $docTypeConfig->allowed_mimes)) {
            throw new DocumentTypeNotAllowedException(
                "File type {$file->getMimeType()} is not allowed. Allowed types: ".implode(', ', $docTypeConfig->allowed_mimes)
            );
        }

        // Check file size
        $maxBytes = $docTypeConfig->max_size_mb * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new DocumentTypeNotAllowedException(
                "File size exceeds maximum of {$docTypeConfig->max_size_mb}MB"
            );
        }
    }

    protected function audit(
        Envelope $envelope,
        string $action,
        ?Model $actor = null,
        ?string $actorRole = null,
        mixed $before = null,
        mixed $after = null,
        ?array $metadata = null
    ): void {
        if (! config('settlement-envelope.audit.enabled', true)) {
            return;
        }

        EnvelopeAuditLog::log($envelope, $action, $actor, $actorRole, $before, $after, $metadata);
    }
}
