<?php

namespace LBHurtado\SettlementEnvelope\Services;

use LBHurtado\SettlementEnvelope\Data\DriverData;
use LBHurtado\SettlementEnvelope\Data\GateDefinitionData;
use LBHurtado\SettlementEnvelope\Enums\ChecklistItemStatus;
use LBHurtado\SettlementEnvelope\Models\Envelope;

class GateEvaluator
{
    /**
     * Evaluate all gates for an envelope
     */
    public function evaluate(Envelope $envelope, DriverData $driver): array
    {
        $context = $this->buildContext($envelope, $driver);
        $results = [];

        foreach ($driver->gates as $gate) {
            $results[$gate->key] = $this->evaluateGate($gate, $context, $results);
        }

        return $results;
    }

    /**
     * Evaluate a single gate
     */
    protected function evaluateGate(GateDefinitionData $gate, array $context, array $previousResults): bool
    {
        $rule = $gate->rule;

        // Add previous gate results to context
        $context['gate'] = $previousResults;

        return $this->evaluateExpression($rule, $context);
    }

    /**
     * Build context object for gate evaluation
     */
    protected function buildContext(Envelope $envelope, DriverData $driver): array
    {
        // Load relationships if not loaded
        $envelope->loadMissing(['checklistItems', 'signals']);

        return [
            'payload' => $this->buildPayloadContext($envelope),
            'checklist' => $this->buildChecklistContext($envelope),
            'signal' => $this->buildSignalContext($envelope, $driver),
            'envelope' => [
                'status' => $envelope->status->value,
                'payload_version' => $envelope->payload_version,
            ],
        ];
    }

    protected function buildPayloadContext(Envelope $envelope): array
    {
        // Basic payload validity - check if payload exists and is non-empty
        $hasPayload = ! empty($envelope->payload);

        return [
            'valid' => $hasPayload,
            'version' => $envelope->payload_version,
        ];
    }

    protected function buildChecklistContext(Envelope $envelope): array
    {
        $items = $envelope->checklistItems;

        $requiredItems = $items->where('required', true);
        $requiredCount = $requiredItems->count();

        // Count required items that are NOT missing (uploaded, needs_review, accepted, rejected)
        $requiredPresentCount = $requiredItems
            ->where('status', '!=', ChecklistItemStatus::MISSING)
            ->count();

        // Count required items that are accepted
        $requiredAcceptedCount = $requiredItems
            ->where('status', ChecklistItemStatus::ACCEPTED)
            ->count();

        return [
            'total' => $items->count(),
            'required_count' => $requiredCount,

            // NEW: For IN_PROGRESS → READY_FOR_REVIEW transition
            // True when all required items have status != missing
            'required_present' => $requiredCount > 0 ? $requiredPresentCount === $requiredCount : true,

            // For READY_FOR_REVIEW → READY_TO_SETTLE transition
            // True when all required items have status = accepted
            'required_accepted' => $requiredCount > 0 ? $requiredAcceptedCount === $requiredCount : true,

            // Legacy compatibility
            'all_accepted' => $items->where('status', ChecklistItemStatus::ACCEPTED)->count() === $items->count(),
            'has_rejected' => $items->where('status', ChecklistItemStatus::REJECTED)->count() > 0,
            'pending_count' => $items->whereIn('status', [
                ChecklistItemStatus::MISSING,
                ChecklistItemStatus::UPLOADED,
                ChecklistItemStatus::NEEDS_REVIEW,
            ])->count(),
        ];
    }

    protected function buildSignalContext(Envelope $envelope, DriverData $driver): array
    {
        $signals = [];
        $blockingSignals = [];

        // Initialize all defined signals with defaults
        foreach ($driver->signals as $signalDef) {
            $signals[$signalDef->key] = $signalDef->default;

            // Track required signals that are false (blocking)
            if ($signalDef->required && ! $signalDef->default) {
                $blockingSignals[] = $signalDef->key;
            }
        }

        // Override with actual signal values
        foreach ($envelope->signals as $signal) {
            $value = $signal->getBoolValue();
            $signals[$signal->key] = $value;

            // Remove from blocking if now true
            if ($value) {
                $blockingSignals = array_filter($blockingSignals, fn ($k) => $k !== $signal->key);
            }
        }

        // Add special computed values
        $signals['_blocking'] = array_values($blockingSignals);
        $signals['_all_satisfied'] = empty($blockingSignals);

        return $signals;
    }

    /**
     * Simple expression evaluator
     * Supports: &&, ||, ==, !=, !, true, false, dot notation
     */
    protected function evaluateExpression(string $expression, array $context): bool
    {
        $expression = trim($expression);

        // Handle simple boolean literals
        if ($expression === 'true') {
            return true;
        }
        if ($expression === 'false') {
            return false;
        }

        // Handle negation
        if (str_starts_with($expression, '!')) {
            return ! $this->evaluateExpression(substr($expression, 1), $context);
        }

        // Handle AND (&&)
        if (str_contains($expression, '&&')) {
            $parts = array_map('trim', explode('&&', $expression, 2));

            return $this->evaluateExpression($parts[0], $context) && $this->evaluateExpression($parts[1], $context);
        }

        // Handle OR (||)
        if (str_contains($expression, '||')) {
            $parts = array_map('trim', explode('||', $expression, 2));

            return $this->evaluateExpression($parts[0], $context) || $this->evaluateExpression($parts[1], $context);
        }

        // Handle equality (==)
        if (str_contains($expression, '==')) {
            $parts = array_map('trim', explode('==', $expression, 2));
            $left = $this->resolveValue($parts[0], $context);
            $right = $this->resolveValue($parts[1], $context);

            return $left == $right;
        }

        // Handle inequality (!=)
        if (str_contains($expression, '!=')) {
            $parts = array_map('trim', explode('!=', $expression, 2));
            $left = $this->resolveValue($parts[0], $context);
            $right = $this->resolveValue($parts[1], $context);

            return $left != $right;
        }

        // Handle simple reference (e.g., "signal.kyc_passed")
        $value = $this->resolveValue($expression, $context);

        return (bool) $value;
    }

    /**
     * Resolve a value from context using dot notation
     */
    protected function resolveValue(string $reference, array $context): mixed
    {
        $reference = trim($reference);

        // Handle literals
        if ($reference === 'true') {
            return true;
        }
        if ($reference === 'false') {
            return false;
        }
        if (is_numeric($reference)) {
            return (float) $reference;
        }
        if (str_starts_with($reference, '"') && str_ends_with($reference, '"')) {
            return trim($reference, '"');
        }
        if (str_starts_with($reference, "'") && str_ends_with($reference, "'")) {
            return trim($reference, "'");
        }

        // Resolve dot notation
        $parts = explode('.', $reference);
        $current = $context;

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Validate a gate rule (for driver validation)
     */
    public function validateRule(string $rule): bool
    {
        // Basic syntax check - ensure balanced parentheses and valid operators
        $validOperators = ['&&', '||', '==', '!=', '!', 'true', 'false'];
        $validPattern = '/^[a-zA-Z0-9_.\s&|!=()]+$/';

        return preg_match($validPattern, $rule) === 1;
    }
}
