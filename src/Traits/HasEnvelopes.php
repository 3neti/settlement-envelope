<?php

namespace LBHurtado\SettlementEnvelope\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;

/**
 * Trait HasEnvelopes
 *
 * Add this trait to any model that needs settlement envelope functionality.
 * Example: Add to Voucher model for settlement voucher support.
 */
trait HasEnvelopes
{
    /**
     * Get the primary envelope for this model
     */
    public function envelope(): MorphOne
    {
        return $this->morphOne(Envelope::class, 'reference');
    }

    /**
     * Get all envelopes for this model (if multiple are needed)
     */
    public function envelopes(): MorphMany
    {
        return $this->morphMany(Envelope::class, 'reference');
    }

    /**
     * Create a new envelope for this model
     */
    public function createEnvelope(
        string $driverId,
        ?string $driverVersion = null,
        ?array $initialPayload = null,
        ?array $context = null,
        ?Model $actor = null
    ): Envelope {
        $service = app(EnvelopeService::class);

        // Use a default reference code based on model's primary key or custom attribute
        $referenceCode = $this->getEnvelopeReferenceCode();

        return $service->create(
            referenceCode: $referenceCode,
            driverId: $driverId,
            driverVersion: $driverVersion,
            reference: $this,
            initialPayload: $initialPayload,
            context: $context,
            actor: $actor
        );
    }

    /**
     * Get envelope reference code for this model
     * Override in your model to customize
     */
    public function getEnvelopeReferenceCode(): string
    {
        // Default: Use 'code' attribute if exists, otherwise use model key with prefix
        if (property_exists($this, 'code') || isset($this->code)) {
            return $this->code;
        }

        $modelName = class_basename($this);

        return strtoupper("{$modelName}-{$this->getKey()}");
    }

    /**
     * Check if this model has an envelope
     */
    public function hasEnvelope(): bool
    {
        return $this->envelope()->exists();
    }

    /**
     * Check if the envelope is settleable
     */
    public function isEnvelopeSettleable(): bool
    {
        return $this->envelope?->isSettleable() ?? false;
    }

    /**
     * Get envelope gate value
     */
    public function getEnvelopeGate(string $gate): mixed
    {
        return $this->envelope?->getGate($gate);
    }

    /**
     * Get envelope signal value
     */
    public function getEnvelopeSignal(string $key): mixed
    {
        return $this->envelope?->getSignal($key);
    }

    /**
     * Set envelope signal value
     */
    public function setEnvelopeSignal(string $key, mixed $value, ?Model $actor = null): void
    {
        if (! $this->envelope) {
            return;
        }

        $service = app(EnvelopeService::class);
        $service->setSignal($this->envelope, $key, $value, $actor);
    }

    /**
     * Get envelope checklist status summary
     */
    public function getEnvelopeChecklistStatus(): ?array
    {
        return $this->envelope?->getChecklistStatus();
    }

    /**
     * Get envelope status
     */
    public function getEnvelopeStatus(): ?string
    {
        return $this->envelope?->status->value;
    }
}
