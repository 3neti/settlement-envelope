<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $envelope_id
 * @property int $version
 * @property array $payload
 * @property array|null $patch
 * @property string|null $payload_hash
 * @property int|null $actor_id
 * @property string|null $actor_type
 * @property \Carbon\Carbon $created_at
 */
class EnvelopePayloadVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'envelope_id',
        'version',
        'payload',
        'patch',
        'payload_hash',
        'actor_id',
        'actor_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'patch' => 'array',
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

    public static function createVersion(Envelope $envelope, array $payload, ?array $patch, ?Model $actor): self
    {
        $hash = hash('sha256', json_encode($payload));

        return self::create([
            'envelope_id' => $envelope->id,
            'version' => $envelope->payload_version + 1,
            'payload' => $payload,
            'patch' => $patch,
            'payload_hash' => $hash,
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? get_class($actor) : null,
            'created_at' => now(),
        ]);
    }
}
