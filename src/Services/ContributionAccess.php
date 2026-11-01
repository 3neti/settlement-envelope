<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Services;

use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeContributionToken;

/** Resolves the scope of an already-issued contribution capability. */
final class ContributionAccess
{
    public function canUpload(
        EnvelopeContributionToken $token,
        Envelope $envelope,
        string $documentType,
        bool $passwordVerified = false,
    ): bool {
        if ((string) $token->envelope_id !== (string) $envelope->getKey()
            || ! $token->isValid()
            || ($token->requiresPassword() && ! $passwordVerified)) {
            return false;
        }

        $actions = data_get($token->metadata, 'scope.actions');
        $types = data_get($token->metadata, 'scope.document_types');

        return is_array($actions) && array_is_list($actions)
            && is_array($types) && array_is_list($types)
            && in_array('upload', $actions, true)
            && in_array($documentType, $types, true);
    }
}
