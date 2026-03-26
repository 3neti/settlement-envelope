<?php

namespace LBHurtado\SettlementEnvelope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $envelope_id
 * @property string $token
 * @property string|null $label
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property string|null $recipient_mobile
 * @property array|null $metadata
 * @property string|null $password
 * @property int|null $created_by
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property int $use_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EnvelopeContributionToken extends Model
{
    protected $fillable = [
        'envelope_id',
        'token',
        'label',
        'recipient_name',
        'recipient_email',
        'recipient_mobile',
        'metadata',
        'password',
        'created_by',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'use_count',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'password' => 'hashed',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'password',
    ];

    // Boot

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $token) {
            if (empty($token->token)) {
                $token->token = (string) Str::uuid();
            }
        });
    }

    // Relationships

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('settlement-envelope.user_model', 'App\\Models\\User'), 'created_by');
    }

    // Domain Methods

    /**
     * Check if the token is valid (not expired, not revoked).
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the token is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the token is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Check if the token requires a password.
     */
    public function requiresPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Verify the provided password.
     */
    public function verifyPassword(string $password): bool
    {
        if (! $this->requiresPassword()) {
            return true;
        }

        return Hash::check($password, $this->password);
    }

    /**
     * Record token usage.
     */
    public function recordUsage(): void
    {
        $this->increment('use_count');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Revoke the token.
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    /**
     * Get a display name for the contributor.
     */
    public function getDisplayName(): string
    {
        if ($this->recipient_name) {
            return $this->recipient_name;
        }

        if ($this->label) {
            return $this->label;
        }

        return 'External Contributor';
    }

    /**
     * Generate a signed contribution URL for this token.
     */
    public function generateUrl(?string $voucherCode = null): string
    {
        // Get voucher code from envelope if not provided
        if ($voucherCode === null) {
            $voucherCode = $this->envelope->reference?->code
                ?? $this->envelope->reference_code;
        }

        return URL::signedRoute('contribute.show', [
            'voucher' => $voucherCode,
            'token' => $this->token,
        ], $this->expires_at);
    }

    // Scopes

    public function scopeValid($query)
    {
        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeForEnvelope($query, Envelope $envelope)
    {
        return $query->where('envelope_id', $envelope->id);
    }

    public function scopeByToken($query, string $token)
    {
        return $query->where('token', $token);
    }
}
