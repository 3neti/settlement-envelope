<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

class WorkflowPlanData extends Data
{
    public function __construct(
        public string $code,
        public string $version,
        public string $title,
        public string $currency,
        public int $premium_minor,
        public ?int $benefit_minor = null,
        public ?WorkflowCoverageData $coverage = null,
    ) {}
}
