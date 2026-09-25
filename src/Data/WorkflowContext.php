<?php

namespace LBHurtado\SettlementEnvelope\Data;

use InvalidArgumentException;

final readonly class WorkflowContext
{
    public function __construct(public string $actorId, public string $accountId)
    {
        foreach ([$actorId, $accountId] as $value) {
            if (trim($value) === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Invalid workflow context.');
            }
        }
    }
}
