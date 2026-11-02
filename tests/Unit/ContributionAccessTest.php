<?php

use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeContributionToken;
use LBHurtado\SettlementEnvelope\Services\ContributionAccess;

function scopedContribution(): EnvelopeContributionToken
{
    return new EnvelopeContributionToken([
        'envelope_id' => 12,
        'expires_at' => now()->addHour(),
        'metadata' => ['scope' => ['actions' => ['upload'], 'document_types' => ['IDENTITY']]],
    ]);
}

function contributionEnvelope(int $id = 12): Envelope
{
    $envelope = new Envelope;
    $envelope->id = $id;

    return $envelope;
}

it('allows only the explicitly scoped document and envelope', function (): void {
    $access = new ContributionAccess;
    $token = scopedContribution();

    expect($access->canUpload($token, contributionEnvelope(), 'IDENTITY'))->toBeTrue()
        ->and($access->canUpload($token, contributionEnvelope(), 'INVOICE'))->toBeFalse()
        ->and($access->canUpload($token, contributionEnvelope(13), 'IDENTITY'))->toBeFalse();
});

it('fails closed for absent, malformed, expired, and revoked scopes', function (): void {
    $access = new ContributionAccess;
    $token = scopedContribution();
    $token->metadata = [];
    expect($access->canUpload($token, contributionEnvelope(), 'IDENTITY'))->toBeFalse();
    $token = scopedContribution();
    $token->expires_at = now()->subSecond();
    expect($access->canUpload($token, contributionEnvelope(), 'IDENTITY'))->toBeFalse();
    $token = scopedContribution();
    $token->revoked_at = now();
    expect($access->canUpload($token, contributionEnvelope(), 'IDENTITY'))->toBeFalse();
});

it('requires a verified password session for protected invitations', function (): void {
    $token = scopedContribution();
    $token->password = 'secret';
    $access = new ContributionAccess;

    expect($access->canUpload($token, contributionEnvelope(), 'IDENTITY'))->toBeFalse()
        ->and($access->canUpload($token, contributionEnvelope(), 'IDENTITY', true))->toBeTrue();
});
