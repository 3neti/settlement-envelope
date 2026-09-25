<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class WorkflowDescriptor extends Data
{
    public function __construct(
        public string $id,
        public string $version,
        public string $title,
        public WorkflowDefinitionData $workflow,
        public WorkflowReadinessData $readiness,
        #[DataCollectionOf(DocumentTypeData::class)]
        public DataCollection $documents,
        #[DataCollectionOf(ChecklistTemplateItemData::class)]
        public DataCollection $checklist,
        /** @var list<string> */
        public array $gates,
    ) {}
}
