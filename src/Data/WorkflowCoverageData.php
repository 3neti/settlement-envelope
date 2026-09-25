<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

class WorkflowCoverageData extends Data
{
    public function __construct(
        public string $basis,
        public ?int $duration_days = null,
        public ?string $description = null,
    ) {}
}
