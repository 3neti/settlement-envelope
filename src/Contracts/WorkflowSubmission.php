<?php

namespace LBHurtado\SettlementEnvelope\Contracts;

interface WorkflowSubmission
{
    public function workflowId(): string;

    public function workflowVersion(): string;

    public function idempotencyKey(): string;

    public function fingerprint(): string;
}
