<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAttachment;
use LBHurtado\SettlementEnvelope\Models\EnvelopeContributionToken;
use RuntimeException;

final readonly class UploadInvitedContribution
{
    public function __construct(
        private ContributionAccess $access,
        private EnvelopeService $envelopes,
    ) {}

    /** Password verification is determined by the caller's server-side session. */
    public function handle(
        Envelope $envelope,
        EnvelopeContributionToken $token,
        string $documentType,
        UploadedFile $file,
        bool $passwordVerified = false,
    ): EnvelopeAttachment {
        $disk = (string) config('settlement-envelope.contribution_storage_disk', 'local');
        if (config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new RuntimeException('Contribution storage must be private.');
        }

        return DB::transaction(function () use ($envelope, $token, $documentType, $file, $passwordVerified, $disk): EnvelopeAttachment {
            $lockedEnvelope = Envelope::query()->lockForUpdate()->findOrFail($envelope->getKey());
            $lockedToken = EnvelopeContributionToken::query()->lockForUpdate()->findOrFail($token->getKey());

            if (! $this->access->canUpload($lockedToken, $lockedEnvelope, $documentType, $passwordVerified)) {
                throw new AuthorizationException('Contribution is not authorized.');
            }

            $attachment = $this->envelopes->uploadAttachment(
                envelope: $lockedEnvelope,
                docType: $documentType,
                file: $file,
                metadata: ['contributor_role' => 'invited', 'contribution_token_id' => $lockedToken->getKey()],
                storageDisk: $disk,
            );
            $lockedToken->recordUsage();

            return $attachment;
        });
    }
}
