<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $envelope_id
 * @property int|null $checklist_item_id
 * @property string $doc_type
 * @property string $original_filename
 * @property string $file_path
 * @property string $disk
 * @property string $mime_type
 * @property int $size
 * @property string $hash
 * @property array|null $metadata
 * @property int|null $uploaded_by
 * @property string $review_status
 * @property int|null $reviewer_id
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EnvelopeAttachment extends Model
{
    protected $fillable = [
        'envelope_id',
        'checklist_item_id',
        'doc_type',
        'original_filename',
        'file_path',
        'disk',
        'mime_type',
        'size',
        'hash',
        'metadata',
        'uploaded_by',
        'review_status',
        'reviewer_id',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(EnvelopeChecklistItem::class, 'checklist_item_id');
    }

    public function getUrl(): string
    {
        return Storage::disk($this->disk)->url($this->file_path);
    }

    public function getTemporaryUrl(int $minutes = 5): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->file_path,
            now()->addMinutes($minutes)
        );
    }

    public function getHumanReadableSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function isPending(): bool
    {
        return $this->review_status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->review_status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->review_status === 'rejected';
    }

    public function accept(?int $reviewerId = null): void
    {
        $this->update([
            'review_status' => 'accepted',
            'reviewer_id' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->checklistItem?->computeStatus();
    }

    public function reject(?int $reviewerId = null, ?string $reason = null): void
    {
        $this->update([
            'review_status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->checklistItem?->computeStatus();
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('review_status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('review_status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('review_status', 'rejected');
    }

    public function scopeByDocType($query, string $docType)
    {
        return $query->where('doc_type', $docType);
    }
}
