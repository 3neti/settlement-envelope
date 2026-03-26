<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $envelope_id
 * @property string $key
 * @property string $type
 * @property string|null $value
 * @property string $source
 * @property int|null $actor_id
 * @property string|null $actor_type
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EnvelopeSignal extends Model
{
    protected $fillable = [
        'envelope_id',
        'key',
        'type',
        'value',
        'source',
        'actor_id',
        'actor_type',
    ];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function getBoolValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    public function setValue(mixed $value, ?Model $actor = null): void
    {
        $this->update([
            'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? get_class($actor) : null,
        ]);
    }

    public static function setSignal(
        Envelope $envelope,
        string $key,
        mixed $value,
        string $type = 'boolean',
        string $source = 'host',
        ?Model $actor = null
    ): self {
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return self::updateOrCreate(
            [
                'envelope_id' => $envelope->id,
                'key' => $key,
            ],
            [
                'type' => $type,
                'value' => $stringValue,
                'source' => $source,
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor ? get_class($actor) : null,
            ]
        );
    }

    // Scopes

    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    public function scopeTrue($query)
    {
        return $query->whereIn('value', ['true', '1', 'yes']);
    }
}
