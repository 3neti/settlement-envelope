<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LBHurtado\SettlementEnvelope\Exceptions\EnvelopeNotEditableException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAuditLog;
use LBHurtado\SettlementEnvelope\Models\EnvelopeContributionToken;

/** The caller is responsible for authorizing the actor for this envelope. */
final readonly class CreateContributionInvitation
{
    public function __construct(private DriverService $drivers) {}

    /** @param list<string> $documentTypes */
    public function handle(Envelope $envelope, Model $actor, array $documentTypes, CarbonInterface $expiresAt, ?string $password = null): EnvelopeContributionToken
    {
        if (! array_is_list($documentTypes) || $documentTypes === [] || count($documentTypes) > 20
            || count(array_filter($documentTypes, 'is_string')) !== count($documentTypes)
            || count(array_unique($documentTypes)) !== count($documentTypes)
            || $expiresAt->lessThanOrEqualTo(now()) || $expiresAt->greaterThan(now()->addDays(90))
            || ($password !== null && mb_strlen($password) < 8)) {
            throw new InvalidArgumentException('Invalid contribution invitation.');
        }

        return DB::transaction(function () use ($envelope, $actor, $documentTypes, $expiresAt, $password): EnvelopeContributionToken {
            $locked = Envelope::query()->lockForUpdate()->findOrFail($envelope->getKey());
            if (! $locked->canEdit()) {
                throw new EnvelopeNotEditableException('The envelope is not editable.');
            }

            $driver = $this->drivers->load($locked->driver_id, $locked->driver_version);
            foreach ($documentTypes as $type) {
                if (! is_string($type) || $driver->getDocumentType($type) === null) {
                    throw new InvalidArgumentException('Invitation contains an unsupported document type.');
                }
            }

            $token = EnvelopeContributionToken::query()->create([
                'envelope_id' => $locked->getKey(),
                'created_by' => $actor->getKey(),
                'expires_at' => $expiresAt,
                'password' => $password,
                'metadata' => ['scope' => ['actions' => ['upload', 'read_own'], 'document_types' => $documentTypes]],
            ]);
            EnvelopeAuditLog::log($locked, EnvelopeAuditLog::ACTION_CONTRIBUTION_TOKEN_CREATED, actor: $actor, after: [
                'token_id' => $token->getKey(), 'document_types' => $documentTypes, 'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $token;
        });
    }
}
