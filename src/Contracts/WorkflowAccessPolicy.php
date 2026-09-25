<?php

namespace LBHurtado\SettlementEnvelope\Contracts;

use LBHurtado\SettlementEnvelope\Data\WorkflowContext;

interface WorkflowAccessPolicy
{
    public function allows(WorkflowContext $context, string $id, string $version): bool;
}
