<?php

use LBHurtado\SettlementEnvelope\Services\PayloadValidator;

beforeEach(function () {
    $this->validator = new PayloadValidator;
});

describe('JSON pointer parsing', function () {
    test('fieldExists returns true for existing field', function () {
        $payload = ['name' => 'John'];
        expect($this->validator->fieldExists($payload, '/name'))->toBeTrue();
    });

    test('fieldExists returns false for missing field', function () {
        $payload = ['name' => 'John'];
        expect($this->validator->fieldExists($payload, '/age'))->toBeFalse();
    });

    test('fieldExists handles nested paths', function () {
        $payload = [
            'borrower' => [
                'full_name' => 'Juan Dela Cruz',
                'contact' => [
                    'mobile' => '09171234567',
                ],
            ],
        ];

        expect($this->validator->fieldExists($payload, '/borrower/full_name'))->toBeTrue();
        expect($this->validator->fieldExists($payload, '/borrower/contact/mobile'))->toBeTrue();
        expect($this->validator->fieldExists($payload, '/borrower/email'))->toBeFalse();
    });

    test('fieldExists handles empty pointer', function () {
        $payload = ['name' => 'John'];
        expect($this->validator->fieldExists($payload, ''))->toBeTrue();
        expect($this->validator->fieldExists($payload, '/'))->toBeTrue();
    });

    test('fieldExists handles array indices', function () {
        $payload = [
            'items' => [
                ['id' => 1],
                ['id' => 2],
            ],
        ];

        expect($this->validator->fieldExists($payload, '/items/0'))->toBeTrue();
        expect($this->validator->fieldExists($payload, '/items/0/id'))->toBeTrue();
        expect($this->validator->fieldExists($payload, '/items/5'))->toBeFalse();
    });
});

describe('getFieldValue', function () {
    test('gets value from simple path', function () {
        $payload = ['name' => 'John', 'age' => 30];
        expect($this->validator->getFieldValue($payload, '/name'))->toBe('John');
        expect($this->validator->getFieldValue($payload, '/age'))->toBe(30);
    });

    test('gets value from nested path', function () {
        $payload = [
            'loan' => [
                'tcp' => 2000000,
                'amount' => 1800000,
                'ltv' => 0.9,
            ],
        ];

        expect($this->validator->getFieldValue($payload, '/loan/tcp'))->toBe(2000000);
        expect($this->validator->getFieldValue($payload, '/loan/ltv'))->toBe(0.9);
    });

    test('returns null for missing path', function () {
        $payload = ['name' => 'John'];
        expect($this->validator->getFieldValue($payload, '/missing'))->toBeNull();
        expect($this->validator->getFieldValue($payload, '/deeply/nested/path'))->toBeNull();
    });

    test('handles special characters in JSON pointer', function () {
        // JSON pointer escapes: ~0 = ~, ~1 = /
        $payload = ['key/with/slashes' => 'value1', 'key~with~tildes' => 'value2'];

        // To reference "key/with/slashes", use ~1 escape
        expect($this->validator->getFieldValue($payload, '/key~1with~1slashes'))->toBe('value1');
        // To reference "key~with~tildes", use ~0 escape
        expect($this->validator->getFieldValue($payload, '/key~0with~0tildes'))->toBe('value2');
    });
});

describe('mergePatch', function () {
    test('merges simple patches', function () {
        $existing = ['name' => 'John', 'age' => 30];
        $patch = ['age' => 31, 'city' => 'Manila'];

        $result = $this->validator->mergePatch($existing, $patch);

        expect($result)->toBe([
            'name' => 'John',
            'age' => 31,
            'city' => 'Manila',
        ]);
    });

    test('merges nested patches recursively', function () {
        $existing = [
            'borrower' => [
                'name' => 'John',
                'contact' => [
                    'mobile' => '09171234567',
                ],
            ],
        ];

        $patch = [
            'borrower' => [
                'contact' => [
                    'email' => 'john@example.com',
                ],
            ],
        ];

        $result = $this->validator->mergePatch($existing, $patch);

        expect($result['borrower']['name'])->toBe('John');
        expect($result['borrower']['contact']['mobile'])->toBe('09171234567');
        expect($result['borrower']['contact']['email'])->toBe('john@example.com');
    });

    test('preserves existing data when patching', function () {
        $existing = [
            'loan' => [
                'tcp' => 2000000,
                'amount' => 1800000,
            ],
        ];

        $patch = [
            'loan' => [
                'ltv' => 0.9,
            ],
        ];

        $result = $this->validator->mergePatch($existing, $patch);

        expect($result['loan'])->toBe([
            'tcp' => 2000000,
            'amount' => 1800000,
            'ltv' => 0.9,
        ]);
    });
});

describe('computeDiff', function () {
    test('detects added fields', function () {
        $old = ['name' => 'John'];
        $new = ['name' => 'John', 'age' => 30];

        $diff = $this->validator->computeDiff($old, $new);

        expect($diff)->toHaveKey('age');
        expect($diff['age'])->toBe(['added' => 30]);
    });

    test('detects removed fields', function () {
        $old = ['name' => 'John', 'age' => 30];
        $new = ['name' => 'John'];

        $diff = $this->validator->computeDiff($old, $new);

        expect($diff)->toHaveKey('age');
        expect($diff['age'])->toBe(['removed' => 30]);
    });

    test('detects changed fields', function () {
        $old = ['name' => 'John', 'age' => 30];
        $new = ['name' => 'John', 'age' => 31];

        $diff = $this->validator->computeDiff($old, $new);

        expect($diff)->toHaveKey('age');
        expect($diff['age'])->toBe(['from' => 30, 'to' => 31]);
    });

    test('detects nested changes', function () {
        $old = ['user' => ['name' => 'John', 'age' => 30]];
        $new = ['user' => ['name' => 'John', 'age' => 31]];

        $diff = $this->validator->computeDiff($old, $new);

        expect($diff)->toHaveKey('user');
        expect($diff['user'])->toHaveKey('age');
    });

    test('returns empty diff for identical payloads', function () {
        $payload = ['name' => 'John', 'age' => 30];

        $diff = $this->validator->computeDiff($payload, $payload);

        expect($diff)->toBe([]);
    });
});

describe('JSON Schema validation', function () {
    test('validates against inline schema', function () {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        $validPayload = ['name' => 'John', 'age' => 30];

        // Should not throw
        expect(fn () => $this->validator->validate($validPayload, null, $schema))
            ->not->toThrow(Exception::class);
    });

    test('throws on invalid payload', function () {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $invalidPayload = ['age' => 30]; // missing required 'name'

        expect(fn () => $this->validator->validate($invalidPayload, null, $schema))
            ->toThrow(\LBHurtado\SettlementEnvelope\Exceptions\PayloadValidationException::class);
    });

    test('passes validation when no schema provided', function () {
        $payload = ['anything' => 'goes'];

        // No schema = assume valid
        expect($this->validator->validate($payload, null, null))->toBeTrue();
    });
});
