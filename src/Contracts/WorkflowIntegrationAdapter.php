<?php

namespace LBHurtado\SettlementEnvelope\Contracts;

interface WorkflowIntegrationAdapter
{
    public function workflowId(): string;

    public function workflowVersion(): string;

    public function submit(WorkflowSubmission $submission): WorkflowIntegrationResult;
}
