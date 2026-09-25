<?php

namespace LBHurtado\SettlementEnvelope\Data;

use Spatie\LaravelData\Data;

class WorkflowNotificationData extends Data
{
    /** @param list<string> $placeholders */
    public function __construct(
        public string $event,
        public string $sms,
        public array $placeholders,
        public bool $allow_override = false,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['placeholders' => ['present', 'array', 'list', 'max:20']];
    }
}
