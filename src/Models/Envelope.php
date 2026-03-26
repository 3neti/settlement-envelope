<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Enums\EnvelopeStatus;
use LBHurtado\SettlementEnvelope\Events\EnvelopeCreated;
use LBHurtado\SettlementEnvelope\Events\GateChanged;
use Spatie\LaravelData\WithData;

/**
 * @property int $id
 * @property string $reference_code
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $driver_id
 * @property string $driver_version
 * @property array|null $payload
 * @property int $payload_version
 * @property EnvelopeStatus $status
 * @property array|null $context
 * @property array|null $gates_cache
 * @property \Carbon\Carbon|null $locked_at
 * @property \Carbon\Carbon|null $settled_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Envelope extends Model
{
    use WithData;

    protected $fillable = [
        'reference_code',
        'reference_type',
        'reference_id',
        'driver_id',
        'driver_version',
        'payload',
        'payload_version',
        'status',
        'context',
        'gates_cache',
        'locked_at',
        'settled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'context' => 'array',
            'gates_cache' => 'array',
            'status' => EnvelopeStatus::class,
            'locked_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected $dispatchesEvents = [
        'created' => EnvelopeCreated::class,
    ];

    // Relationships

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function payloadVersions(): HasMany
    {
        return $this->hasMany(EnvelopePayloadVersion::class)->orderBy('version');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(EnvelopeChecklistItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EnvelopeAttachment::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(EnvelopeSignal::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(EnvelopeAuditLog::class)->orderByDesc('created_at');
    }

    public function contributionTokens(): HasMany
    {
        return $this->hasMany(EnvelopeContributionToken::class);
    }

    public function activeContributionTokens(): HasMany
    {
        return $this->contributionTokens()->valid();
    }

    // Domain Methods

    public function getDriverKey(): string
    {
        return "{$this->driver_id}@{$this->driver_version}";
    }

    public function canEdit(): bool
    {
        return $this->status->canEdit();
    }

    public function canSettle(): bool
    {
        return $this->status->canSettle();
    }

    public function isSettleable(): bool
    {
        return $this->getGate('settleable') === true;
    }

    public function getGate(string $key): mixed
    {
        return $this->gates_cache[$key] ?? null;
    }

    public function getGates(): array
    {
        return $this->gates_cache ?? [];
    }

    public function updateGatesCache(array $gates): void
    {
        $oldGates = $this->gates_cache ?? [];
        $this->update(['gates_cache' => $gates]);

        // Fire event if settleable changed
        if (($oldGates['settleable'] ?? null) !== ($gates['settleable'] ?? null)) {
            event(new GateChanged($this, 'settleable', $oldGates['settleable'] ?? null, $gates['settleable'] ?? null));
        }
    }

    public function getSignal(string $key): mixed
    {
        return $this->signals()->where('key', $key)->first()?->value;
    }

    public function getSignalBool(string $key): bool
    {
        $signal = $this->signals()->where('key', $key)->first();
        if (! $signal) {
            return false;
        }

        return filter_var($signal->value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getChecklistStatus(): array
    {
        $items = $this->checklistItems;

        return [
            'total' => $items->count(),
            'required_count' => $items->where('required', true)->count(),
            'completed_count' => $items->where('status', ChecklistItemStatus::ACCEPTED)->count(),
            'required_completed' => $items->where('required', true)
                ->where('status', ChecklistItemStatus::ACCEPTED)->count(),
            'pending_count' => $items->whereIn('status', [
                ChecklistItemStatus::MISSING,
                ChecklistItemStatus::UPLOADED,
                ChecklistItemStatus::NEEDS_REVIEW,
            ])->count(),
            'rejected_count' => $items->where('status', ChecklistItemStatus::REJECTED)->count(),
        ];
    }

    public function isChecklistComplete(): bool
    {
        $status = $this->getChecklistStatus();

        return $status['required_count'] === $status['required_completed'];
    }

    // Scopes

    public function scopeByDriver($query, string $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeSettleable($query)
    {
        return $query->whereJsonContains('gates_cache->settleable', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', EnvelopeStatus::ACTIVE);
    }

    public function scopeByReference($query, string $referenceType, int $referenceId)
    {
        return $query->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId);
    }
}
