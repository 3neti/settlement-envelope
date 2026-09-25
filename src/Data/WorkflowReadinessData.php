<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

class WorkflowReadinessData extends Data
{
    public function __construct(public bool $configured, public ?string $reason = null) {}
}
