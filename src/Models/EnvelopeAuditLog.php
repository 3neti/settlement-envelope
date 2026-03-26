<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $envelope_id
 * @property string $action
 * @property int|null $actor_id
 * @property string|null $actor_type
 * @property string|null $actor_role
 * @property array|null $before
 * @property array|null $after
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 */
class EnvelopeAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'envelope_id',
        'action',
        'actor_id',
        'actor_type',
        'actor_role',
        'before',
        'after',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public static function log(
        Envelope $envelope,
        string $action,
        ?Model $actor = null,
        ?string $actorRole = null,
        mixed $before = null,
        mixed $after = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'envelope_id' => $envelope->id,
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_role' => $actorRole,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    // Action constants
    public const ACTION_CREATED = 'envelope_created';

    public const ACTION_PAYLOAD_PATCH = 'payload_patch';

    public const ACTION_ATTACHMENT_UPLOAD = 'attachment_upload';

    public const ACTION_ATTACHMENT_REVIEW = 'attachment_review';

    public const ACTION_SIGNAL_SET = 'signal_set';

    public const ACTION_STATUS_CHANGE = 'status_change';

    public const ACTION_GATE_CHANGE = 'gate_change';

    public const ACTION_LOCKED = 'envelope_locked';

    public const ACTION_SETTLED = 'envelope_settled';

    public const ACTION_CANCELLED = 'envelope_cancelled';

    public const ACTION_REJECTED = 'envelope_rejected';

    public const ACTION_REOPENED = 'envelope_reopened';

    public const ACTION_CONTEXT_UPDATE = 'context_update';

    public const ACTION_EXTERNAL_CONTRIBUTION = 'external_contribution';

    public const ACTION_CONTRIBUTION_TOKEN_CREATED = 'contribution_token_created';

    public const ACTION_CONTRIBUTION_TOKEN_REVOKED = 'contribution_token_revoked';

    // Scopes

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByActor($query, Model $actor)
    {
        return $query->where('actor_type', get_class($actor))
            ->where('actor_id', $actor->getKey());
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
