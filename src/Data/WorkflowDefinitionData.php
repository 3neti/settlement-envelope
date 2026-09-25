<?php

namespace LBHurtado\SettlementEnvelope\Data;

use LBHurtado\SettlementEnvelope\Enums\WorkflowEntryMethod;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class WorkflowDefinitionData extends Data
{
    /** @param list<WorkflowEntryMethod> $entry_methods */
    public function __construct(
        public string $service,
        public string $title,
        public array $entry_methods,
        #[DataCollectionOf(WorkflowPlanData::class)]
        public DataCollection $plans,
        #[DataCollectionOf(WorkflowNotificationData::class)]
        public DataCollection $notifications,
        public ?string $connection = null,
        public bool $requires_review = false,
    ) {}
}
