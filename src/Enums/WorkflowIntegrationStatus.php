<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum WorkflowIntegrationStatus: string
{
    case Submitted = 'submitted';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
