<?php

namespace LBHurtado\SettlementEnvelope\Services;

use LBHurtado\SettlementEnvelope\Contracts\WorkflowAccessPolicy;
use LBHurtado\SettlementEnvelope\Data\WorkflowContext;

class DenyWorkflowAccess implements WorkflowAccessPolicy
{
    public function allows(WorkflowContext $context, string $id, string $version): bool
    {
        return false;
    }
}
