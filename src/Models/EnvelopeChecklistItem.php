<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemKind;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Enums\ReviewMode;

/**
 * @property int $id
 * @property int $envelope_id
 * @property string $key
 * @property string $label
 * @property ChecklistItemKind $kind
 * @property string|null $doc_type
 * @property string|null $payload_pointer
 * @property string|null $attestation_type
 * @property string|null $signal_key
 * @property bool $required
 * @property ReviewMode $review_mode
 * @property ChecklistItemStatus $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EnvelopeChecklistItem extends Model
{
    protected $fillable = [
        'envelope_id',
        'key',
        'label',
        'kind',
        'doc_type',
        'payload_pointer',
        'attestation_type',
        'signal_key',
        'required',
        'review_mode',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ChecklistItemKind::class,
            'review_mode' => ReviewMode::class,
            'status' => ChecklistItemStatus::class,
            'required' => 'boolean',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EnvelopeAttachment::class, 'checklist_item_id');
    }

    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function requiresUpload(): bool
    {
        return $this->kind->requiresUpload();
    }

    public function requiresReview(): bool
    {
        return $this->review_mode->requiresReview();
    }

    public function getLatestAttachment(): ?EnvelopeAttachment
    {
        return $this->attachments()->latest()->first();
    }

    /**
     * Update status based on current state
     */
    public function computeStatus(): void
    {
        if ($this->kind === ChecklistItemKind::DOCUMENT) {
            $attachment = $this->getLatestAttachment();

            if (! $attachment) {
                $this->update(['status' => ChecklistItemStatus::MISSING]);

                return;
            }

            if ($attachment->review_status === 'accepted') {
                $this->update(['status' => ChecklistItemStatus::ACCEPTED]);
            } elseif ($attachment->review_status === 'rejected') {
                $this->update(['status' => ChecklistItemStatus::REJECTED]);
            } elseif ($this->review_mode->allowsReview()) {
                // Review is optional or required - wait for human review
                $this->update(['status' => ChecklistItemStatus::NEEDS_REVIEW]);
            } else {
                // review: none - no human review needed, uploaded = auto-accepted
                $this->update(['status' => ChecklistItemStatus::ACCEPTED]);
            }
        }
    }

    // Scopes

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function scopeByKind($query, ChecklistItemKind $kind)
    {
        return $query->where('kind', $kind);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            ChecklistItemStatus::MISSING,
            ChecklistItemStatus::UPLOADED,
            ChecklistItemStatus::NEEDS_REVIEW,
        ]);
    }
}
