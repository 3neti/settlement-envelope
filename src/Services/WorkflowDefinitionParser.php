<?php

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Support\Facades\Validator;
use LBHurtado\SettlementEnvelope\Data\WorkflowDefinitionData;
use LBHurtado\SettlementEnvelope\Enums\WorkflowEntryMethod;
use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;

class WorkflowDefinitionParser
{
    public function parse(mixed $definition): WorkflowDefinitionData
    {
        $valid = Validator::make(['workflow' => $definition], [
            'workflow' => ['required', 'array:service,title,entry_methods,plans,notifications,connection,requires_review'],
            'workflow.service' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'workflow.title' => ['required', 'string', 'max:160'],
            'workflow.entry_methods' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'workflow.entry_methods.*' => ['required', 'string', 'distinct', 'in:public_endpoint,payment_qr'],
            'workflow.connection' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/D'],
            'workflow.requires_review' => ['sometimes', 'boolean'],
            'workflow.plans' => ['sometimes', 'array', 'list', 'max:100'],
            'workflow.plans.*' => ['array:code,version,title,currency,premium_minor,benefit_minor,coverage'],
            'workflow.plans.*.code' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'],
            'workflow.plans.*.version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+(?:\.[0-9]+)*$/D'],
            'workflow.plans.*.title' => ['required', 'string', 'max:160'],
            'workflow.plans.*.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'workflow.plans.*.premium_minor' => ['required', 'integer', 'min:0'],
            'workflow.plans.*.benefit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'workflow.plans.*.coverage' => ['sometimes', 'array:basis,duration_days,description'],
            'workflow.plans.*.coverage.basis' => ['required_with:workflow.plans.*.coverage', 'in:day,trip'],
            'workflow.plans.*.coverage.duration_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'workflow.plans.*.coverage.description' => ['sometimes', 'string', 'max:500'],
            'workflow.notifications' => ['sometimes', 'array', 'list', 'max:20'],
            'workflow.notifications.*' => ['array:event,sms,placeholders,allow_override'],
            'workflow.notifications.*.event' => ['required', 'string', 'distinct', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'workflow.notifications.*.sms' => ['required', 'string', 'max:1600'],
            'workflow.notifications.*.placeholders' => ['present', 'array', 'list', 'max:20'],
            'workflow.notifications.*.placeholders.*' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/D'],
            'workflow.notifications.*.allow_override' => ['sometimes', 'boolean'],
        ]);

        if ($valid->fails()) {
            throw new InvalidDriverException('Invalid workflow definition.');
        }

        foreach (['requires_review'] as $key) {
            if (isset($definition[$key]) && ! is_bool($definition[$key])) {
                throw new InvalidDriverException('Invalid workflow boolean.');
            }
        }

        foreach ($definition['plans'] ?? [] as $plan) {
            if (isset($plan['coverage'])) {
                $coverage = $plan['coverage'];
                if (($coverage['basis'] === 'day' && (! is_int($coverage['duration_days'] ?? null) || $coverage['duration_days'] < 1))
                    || ($coverage['basis'] === 'trip' && (isset($coverage['duration_days']) || trim($coverage['description'] ?? '') === ''))) {
                    throw new InvalidDriverException('Invalid workflow coverage terms.');
                }
            }
            foreach (['premium_minor', 'benefit_minor'] as $key) {
                if (isset($plan[$key]) && ! is_int($plan[$key])) {
                    throw new InvalidDriverException('Workflow amounts must be integer minor units.');
                }
            }
        }

        foreach ($definition['notifications'] ?? [] as $notification) {
            if (isset($notification['allow_override']) && ! is_bool($notification['allow_override'])) {
                throw new InvalidDriverException('Invalid notification override flag.');
            }
            preg_match_all('/\{([^{}]+)\}/', $notification['sms'], $matches);
            if (array_diff($matches[1], $notification['placeholders']) !== [] || str_contains(preg_replace('/\{[a-z][a-z0-9_]*\}/', '', $notification['sms']), '{') || str_contains(preg_replace('/\{[a-z][a-z0-9_]*\}/', '', $notification['sms']), '}')) {
                throw new InvalidDriverException('Invalid notification placeholders.');
            }
        }

        $definition['entry_methods'] = array_map(WorkflowEntryMethod::from(...), $definition['entry_methods']);
        $definition['plans'] ??= [];
        $definition['notifications'] ??= [];

        return WorkflowDefinitionData::from($definition);
    }
}
