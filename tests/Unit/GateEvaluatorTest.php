<?php

use LBHurtado\SettlementEnvelope\Services\GateEvaluator;

beforeEach(function () {
    $this->evaluator = new GateEvaluator;
});

// Helper to access protected method
function evaluate(GateEvaluator $evaluator, string $expression, array $context): bool
{
    $reflection = new ReflectionClass($evaluator);
    $method = $reflection->getMethod('evaluateExpression');
    $method->setAccessible(true);

    return $method->invoke($evaluator, $expression, $context);
}

function resolveRef(GateEvaluator $evaluator, string $reference, array $context): mixed
{
    $reflection = new ReflectionClass($evaluator);
    $method = $reflection->getMethod('resolveValue');
    $method->setAccessible(true);

    return $method->invoke($evaluator, $reference, $context);
}

describe('boolean literals', function () {
    test('evaluates true literal', function () {
        expect(evaluate($this->evaluator, 'true', []))->toBeTrue();
    });

    test('evaluates false literal', function () {
        expect(evaluate($this->evaluator, 'false', []))->toBeFalse();
    });
});

describe('negation', function () {
    test('negates true to false', function () {
        expect(evaluate($this->evaluator, '!true', []))->toBeFalse();
    });

    test('negates false to true', function () {
        expect(evaluate($this->evaluator, '!false', []))->toBeTrue();
    });

    test('negates signal reference', function () {
        $context = ['signal' => ['approved' => true]];
        expect(evaluate($this->evaluator, '!signal.approved', $context))->toBeFalse();
    });
});

describe('AND expressions', function () {
    test('true AND true equals true', function () {
        expect(evaluate($this->evaluator, 'true && true', []))->toBeTrue();
    });

    test('true AND false equals false', function () {
        expect(evaluate($this->evaluator, 'true && false', []))->toBeFalse();
    });

    test('false AND true equals false', function () {
        expect(evaluate($this->evaluator, 'false && true', []))->toBeFalse();
    });

    test('evaluates chained AND expressions', function () {
        expect(evaluate($this->evaluator, 'true && true && true', []))->toBeTrue();
        expect(evaluate($this->evaluator, 'true && true && false', []))->toBeFalse();
    });

    test('evaluates AND with signal references', function () {
        $context = [
            'signal' => [
                'kyc_passed' => true,
                'account_created' => true,
            ],
        ];
        expect(evaluate($this->evaluator, 'signal.kyc_passed && signal.account_created', $context))->toBeTrue();

        $context['signal']['account_created'] = false;
        expect(evaluate($this->evaluator, 'signal.kyc_passed && signal.account_created', $context))->toBeFalse();
    });
});

describe('OR expressions', function () {
    test('true OR false equals true', function () {
        expect(evaluate($this->evaluator, 'true || false', []))->toBeTrue();
    });

    test('false OR false equals false', function () {
        expect(evaluate($this->evaluator, 'false || false', []))->toBeFalse();
    });

    test('evaluates OR with signal references', function () {
        $context = [
            'signal' => [
                'option_a' => false,
                'option_b' => true,
            ],
        ];
        expect(evaluate($this->evaluator, 'signal.option_a || signal.option_b', $context))->toBeTrue();
    });
});

describe('equality expressions', function () {
    test('evaluates equality with true', function () {
        $context = ['payload' => ['valid' => true]];
        expect(evaluate($this->evaluator, 'payload.valid == true', $context))->toBeTrue();
    });

    test('evaluates equality with false', function () {
        $context = ['payload' => ['valid' => false]];
        expect(evaluate($this->evaluator, 'payload.valid == false', $context))->toBeTrue();
    });

    test('evaluates inequality', function () {
        $context = ['checklist' => ['pending_count' => 5]];
        expect(evaluate($this->evaluator, 'checklist.pending_count != 0', $context))->toBeTrue();
    });
});

describe('dot notation resolution', function () {
    test('resolves single level', function () {
        $context = ['signal' => ['approved' => true]];
        expect(resolveRef($this->evaluator, 'signal.approved', $context))->toBeTrue();
    });

    test('resolves nested levels', function () {
        $context = [
            'checklist' => [
                'status' => [
                    'complete' => true,
                ],
            ],
        ];
        expect(resolveRef($this->evaluator, 'checklist.status.complete', $context))->toBeTrue();
    });

    test('returns null for missing keys', function () {
        $context = ['signal' => []];
        expect(resolveRef($this->evaluator, 'signal.nonexistent', $context))->toBeNull();
    });

    test('resolves numeric literals', function () {
        expect(resolveRef($this->evaluator, '42', []))->toBe(42.0);
        expect(resolveRef($this->evaluator, '3.14', []))->toBe(3.14);
    });

    test('resolves string literals with double quotes', function () {
        expect(resolveRef($this->evaluator, '"hello"', []))->toBe('hello');
    });

    test('resolves string literals with single quotes', function () {
        expect(resolveRef($this->evaluator, "'world'", []))->toBe('world');
    });
});

describe('gate references', function () {
    test('evaluates gate references from previous results', function () {
        $context = [
            'gate' => [
                'payload_valid' => true,
                'checklist_complete' => true,
            ],
            'signal' => [
                'approved' => true,
            ],
        ];

        expect(evaluate($this->evaluator, 'gate.payload_valid && gate.checklist_complete', $context))->toBeTrue();
    });

    test('complex gate expression', function () {
        $context = [
            'gate' => [
                'evidence_ready' => true,
                'account_ready' => false,
            ],
            'signal' => [
                'underwriting_approved' => true,
            ],
        ];

        // settleable = gate.evidence_ready && gate.account_ready && signal.underwriting_approved
        expect(evaluate($this->evaluator, 'gate.evidence_ready && gate.account_ready && signal.underwriting_approved', $context))->toBeFalse();

        $context['gate']['account_ready'] = true;
        expect(evaluate($this->evaluator, 'gate.evidence_ready && gate.account_ready && signal.underwriting_approved', $context))->toBeTrue();
    });
});

describe('checklist context', function () {
    test('evaluates checklist.required_accepted', function () {
        $context = [
            'checklist' => [
                'required_accepted' => true,
            ],
        ];
        expect(evaluate($this->evaluator, 'checklist.required_accepted == true', $context))->toBeTrue();
    });

    test('evaluates checklist as boolean reference', function () {
        $context = [
            'checklist' => [
                'required_accepted' => true,
            ],
        ];
        expect(evaluate($this->evaluator, 'checklist.required_accepted', $context))->toBeTrue();
    });
});

describe('rule validation', function () {
    test('validates simple rule', function () {
        expect($this->evaluator->validateRule('signal.approved'))->toBeTrue();
    });

    test('validates complex rule', function () {
        expect($this->evaluator->validateRule('gate.payload_valid && gate.checklist_complete && signal.approved'))->toBeTrue();
    });

    test('validates rule with equality', function () {
        expect($this->evaluator->validateRule('payload.valid == true'))->toBeTrue();
    });
});
