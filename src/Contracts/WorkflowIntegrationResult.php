<?php

namespace LBHurtado\SettlementEnvelope\Contracts;

use LBHurtado\SettlementEnvelope\Enums\WorkflowIntegrationStatus;

interface WorkflowIntegrationResult
{
    public function reference(): string;

    public function status(): WorkflowIntegrationStatus;

    public function demonstrationOnly(): bool;
}
