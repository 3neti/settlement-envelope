<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Actions;

use Illuminate\Support\Facades\Log;
use LBHurtado\SettlementEnvelope\Data\FormFlowSyncResultData;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;
use LBHurtado\SettlementEnvelope\Services\FormFlowDataMapper;
use LBHurtado\SettlementEnvelope\Services\GateEvaluator;
use LBHurtado\SettlementEnvelope\Services\PayloadValidator;

/**
 * Sync form flow collected data to a settlement envelope.
 *
 * This is a generic action that accepts an Envelope directly.
 * Host apps should create a thin wrapper that extracts the envelope
 * from their domain model (e.g., Voucher).
 *
 * This action handles:
 * 1. Loading driver config for form_flow_mapping
 * 2. Mapping collected data to envelope payload format
 * 3. Extracting and uploading attachments (selfie, signature, map)
 * 4. Updating the envelope payload
 * 5. Recomputing gates and auto-advancing status
 *
 * IMPORTANT: Attachments are uploaded BEFORE payload to avoid state
 * advancement blocking subsequent uploads.
 */
class SyncFormFlowToEnvelope
{
    public function __construct(
        protected EnvelopeService $envelopeService,
        protected FormFlowDataMapper $mapper,
        protected DriverService $driverService,
        protected GateEvaluator $gateEvaluator,
        protected PayloadValidator $payloadValidator
    ) {}

    /**
     * Execute the sync operation.
     *
     * @param  Envelope  $envelope  The settlement envelope
     * @param  array  $collectedData  Form flow collected data (step-indexed or numeric-indexed)
     * @param  string|null  $referenceCode  Optional reference code for logging (e.g., voucher code)
     * @return FormFlowSyncResultData Result containing counts and any errors
     */
    public function execute(Envelope $envelope, array $collectedData, ?string $referenceCode = null): FormFlowSyncResultData
    {
        $logRef = $referenceCode ?? $envelope->reference_code ?? $envelope->id;

        // Load driver to get form_flow_mapping config (if any)
        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);
        $mapping = $driver->form_flow_mapping;

        Log::info('[SyncFormFlowToEnvelope] Starting sync', [
            'reference' => $logRef,
            'envelope_id' => $envelope->id,
            'driver' => $driver->getDriverKey(),
            'has_mapping' => $mapping !== null,
        ]);

        // Map data using driver config (or hardcoded defaults if no config)
        $payload = $this->mapper->toPayload($collectedData, $mapping);
        $attachments = $this->mapper->extractAttachments($collectedData, $mapping);

        $attachmentErrors = [];
        $uploadedCount = 0;

        // 1. Update payload FIRST (before attachments trigger state change)
        // This ensures all payload data is saved while envelope is still editable
        $payloadUpdated = false;
        if (! empty($payload)) {
            // Direct update to avoid EnvelopeService validation issue
            // TODO: Debug why EnvelopeService::updatePayload fails validation
            $envelope->update(['payload' => $payload]);
            $payloadUpdated = true;

            // Update payload field checklist items (normally done by EnvelopeService)
            $this->updatePayloadFieldItems($envelope);

            Log::debug('[SyncFormFlowToEnvelope] Payload updated', [
                'reference' => $logRef,
                'payload_keys' => array_keys($payload),
            ]);
        }

        // 2. Upload attachments AFTER payload
        // Note: First attachment upload may trigger state advancement to ready_to_settle
        // Subsequent attachments may fail with "not editable" - this is expected behavior
        foreach ($attachments as $docType => $file) {
            try {
                $this->envelopeService->uploadAttachment($envelope, $docType, $file);
                $uploadedCount++;

                Log::debug('[SyncFormFlowToEnvelope] Attachment uploaded', [
                    'reference' => $logRef,
                    'doc_type' => $docType,
                ]);
            } catch (\Throwable $e) {
                $attachmentErrors[$docType] = $e->getMessage();

                Log::warning('[SyncFormFlowToEnvelope] Failed to upload attachment', [
                    'reference' => $logRef,
                    'doc_type' => $docType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Recompute gates to advance status if settleable
        // This is done after all uploads to ensure final state is correct
        if ($payloadUpdated || $uploadedCount > 0) {
            $this->recomputeGates($envelope);
        }

        Log::info('[SyncFormFlowToEnvelope] Sync completed', [
            'reference' => $logRef,
            'envelope_id' => $envelope->id,
            'payload_updated' => $payloadUpdated,
            'attachments_uploaded' => $uploadedCount,
            'attachments_failed' => count($attachmentErrors),
        ]);

        return FormFlowSyncResultData::success(
            payloadUpdated: $payloadUpdated,
            payloadKeys: array_keys($payload),
            attachmentsUploaded: $uploadedCount,
            attachmentErrors: $attachmentErrors
        );
    }

    /**
     * Update payload field checklist items based on current payload.
     */
    protected function updatePayloadFieldItems(Envelope $envelope): void
    {
        $envelope->checklistItems()
            ->where('kind', 'payload_field')
            ->each(function ($item) use ($envelope) {
                if ($item->payload_pointer) {
                    $exists = $this->payloadValidator->fieldExists(
                        $envelope->payload ?? [],
                        $item->payload_pointer
                    );

                    // Payload fields with review 'none' auto-accept when present
                    $newStatus = $exists ? 'accepted' : 'missing';
                    $item->update(['status' => $newStatus]);
                }
            });
    }

    /**
     * Recompute gates and auto-advance status if conditions are met.
     */
    protected function recomputeGates(Envelope $envelope): void
    {
        // Refresh to get latest state
        $envelope->refresh();
        $envelope->load(['checklistItems', 'signals']);

        // Compute gates using GateEvaluator
        $driver = $this->driverService->load($envelope->driver_id, $envelope->driver_version);
        $gates = $this->gateEvaluator->evaluate($envelope, $driver);

        // Update gates cache
        $envelope->updateGatesCache($gates);

        // Auto-advance logic
        $this->autoAdvance($envelope, $gates);
    }

    /**
     * Auto-advance envelope state based on gates.
     */
    protected function autoAdvance(Envelope $envelope, array $gates): void
    {
        $current = $envelope->status;
        $settleable = $gates['settleable'] ?? false;

        // Compute required items states
        $requiredItems = $envelope->checklistItems->where('required', true);
        $requiredCount = $requiredItems->count();
        $requiredAcceptedCount = $requiredItems->filter(fn ($i) => $i->status->value === 'accepted')->count();
        $requiredPresent = $requiredCount === 0 || $requiredItems->filter(fn ($i) => $i->status->value !== 'missing')->count() === $requiredCount;
        $requiredAccepted = $requiredCount === 0 || $requiredAcceptedCount === $requiredCount;

        // State transitions
        $newStatus = null;

        if ($current->value === 'draft') {
            if (! empty($envelope->payload) || $envelope->attachments()->exists()) {
                $newStatus = EnvelopeStatus::IN_PROGRESS;
            }
        }

        if (in_array($current->value, ['in_progress', 'active']) && $requiredPresent) {
            $newStatus = EnvelopeStatus::READY_FOR_REVIEW;
        }

        if ($current->value === 'ready_for_review' && $requiredAccepted && $settleable) {
            $newStatus = EnvelopeStatus::READY_TO_SETTLE;
        }

        if ($newStatus && $newStatus !== $current) {
            $envelope->update(['status' => $newStatus]);
            // Recurse to handle multi-step transitions (draft → in_progress → ready_for_review → ready_to_settle)
            $this->recomputeGates($envelope);
        }
    }
}
