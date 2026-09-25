<?php

namespace LBHurtado\SettlementEnvelope\Contracts;

use LBHurtado\SettlementEnvelope\Data\WorkflowContext;
use LBHurtado\SettlementEnvelope\Data\WorkflowDescriptor;

interface WorkflowCatalog
{
    /** @return list<WorkflowDescriptor> */
    public function available(WorkflowContext $context): array;

    public function resolve(string $id, string $version, WorkflowContext $context): WorkflowDescriptor;
}
